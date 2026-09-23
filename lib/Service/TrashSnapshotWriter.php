<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Files\File;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Bring a pad's content into its trashed file, and say why not when it
 * does not get there: one file, one pad, one try. A try reads the file,
 * then writes into it at trash time or, when the trash could not, in the
 * sweep.
 *
 * Knows nothing about bindings. What a miss means for the row and the pad
 * is the caller's to decide; here a miss is only logged, in one line an
 * admin can filter by `reason`, and handed back.
 *
 * $news: whether the file's trouble would be news (warning) or a repeat
 * (debug); a sweep reads it off the row. $budget: the sweep's run, whose
 * rest each Etherpad call gets; none at trash time.
 */
final class TrashSnapshotWriter {
	/** Test faults, injected on a debug instance through the admin API. */
	public const FAULT_READ_LOCK = 'trash_read_lock';
	public const FAULT_WRITE_LOCK = 'trash_write_lock';
	public const FAULT_WRITE_FAIL = 'trash_write_fail';

	/** @var array<string,mixed> */
	private array $context;

	/**
	 * @param \Closure(string): bool $faultActive
	 */
	public function __construct(
		private EtherpadClient $etherpadClient,
		private PadFileService $padFileService,
		private LoggerInterface $logger,
		private \Closure $faultActive,
		private File $file,
		private string $padId,
		private bool $news = true,
		private ?RunBudget $budget = null,
	) {
		$this->context = ['app' => 'etherpad_nextcloud', 'fileId' => $file->getId()];
	}

	/**
	 * The .pad as a trash reads it, or why there is nothing to take a
	 * snapshot into yet. A delete through WebDAV holds the file's lock while
	 * the trash is decided, so a locked file is the ordinary case here. A
	 * file that cannot be read for another reason is a miss of its own,
	 * reported as the file's trouble.
	 */
	public function read(): ParsedPadFile|TrashSnapshotMiss {
		try {
			if (($this->faultActive)(self::FAULT_READ_LOCK)) {
				throw new LockedException('Injected test fault: trash_read_lock');
			}
			$content = $this->file->getContent();
		} catch (LockedException) {
			return $this->missed(TrashSnapshotMiss::FileLocked);
		} catch (\Throwable $readError) {
			return $this->missed(TrashSnapshotMiss::FileUnreadable, SafeError::context($readError));
		}
		if ($content === '') {
			return $this->missed(TrashSnapshotMiss::FileEmpty);
		}
		try {
			return $this->padFileService->readPad($content);
		} catch (\Throwable $parseError) {
			return $this->missed(TrashSnapshotMiss::FileUnparsable, SafeError::context($parseError));
		}
	}

	/**
	 * Take the pad's current content into the file at trash time. True once
	 * the file holds it, written now or there already: a pad is not deleted
	 * on anything less. Any error on the way is a snapshot not taken, so a
	 * trash never fails on it. A spent budget is not an error of the
	 * snapshot and goes to the caller, as in the sweep.
	 *
	 * @throws RunBudgetSpentException
	 */
	public function writeAtTrash(ParsedPadFile $pad): bool {
		try {
			return $this->take($pad) === true;
		} catch (RunBudgetSpentException $spent) {
			throw $spent;
		} catch (\Throwable $fetchError) {
			$this->missed(TrashSnapshotMiss::SnapshotNotFetched, SafeError::context($fetchError));
			return false;
		}
	}

	/**
	 * Take the pad's current content into the file in the trash, for the
	 * sweep: true once the file holds it, or the miss. Etherpad giving no
	 * answer is the miss SnapshotNotFetched; a spent budget, and any other
	 * error, goes to the caller.
	 *
	 * $revisions: the pad's count, when the caller has just asked for it.
	 * $moved: whether a restore has taken the file back since it was read,
	 * asked right before the write - one through the old node would make a
	 * new file where it was.
	 *
	 * @param \Closure(): bool $moved
	 * @return TrashSnapshotMiss|true
	 * @throws RunBudgetSpentException
	 */
	public function writeInTrash(ParsedPadFile $pad, ?int $revisions, \Closure $moved): TrashSnapshotMiss|bool {
		try {
			return $this->take($pad, $revisions, $moved);
		} catch (EtherpadClientException $etherpadError) {
			return $this->missed(TrashSnapshotMiss::SnapshotNotFetched, SafeError::context($etherpadError));
		}
	}

	/**
	 * Fetch and write: true when the file holds the pad's content, or the
	 * miss. Etherpad's errors are the caller's to place.
	 *
	 * @param ?\Closure(): bool $moved
	 * @return TrashSnapshotMiss|true
	 * @throws RunBudgetSpentException
	 */
	private function take(ParsedPadFile $pad, ?int $revisions = null, ?\Closure $moved = null): TrashSnapshotMiss|bool {
		$snapshot = $this->fresh($pad, $revisions);
		if (!$snapshot instanceof PadSnapshot) {
			return $snapshot;
		}
		if ($moved !== null && $moved()) {
			return $this->missed(TrashSnapshotMiss::FileMoved);
		}
		return $this->writeAndRecount($pad, $snapshot);
	}

	/**
	 * The pad's current content for the file: true when the file holds it
	 * already - the pad has not moved past the file's snapshot revision - a
	 * snapshot to write, or a miss: the pad is behind the file's snapshot,
	 * or changed while it was read. A snapshot is never older than the one
	 * the file has.
	 *
	 * @return PadSnapshot|TrashSnapshotMiss|true
	 * @throws RunBudgetSpentException
	 */
	private function fresh(ParsedPadFile $pad, ?int $revisions = null): PadSnapshot|TrashSnapshotMiss|bool {
		$revisions ??= $this->etherpadClient->getRevisionsCount($this->padId, RunBudget::timeoutOf($this->budget));
		if ($revisions === $pad->snapshotRev) {
			return true;
		}
		if ($revisions < $pad->snapshotRev) {
			return $this->missed(TrashSnapshotMiss::PadBehind);
		}
		return $this->fetchStable($revisions) ?? $this->missed(TrashSnapshotMiss::PadChanged);
	}

	/**
	 * Write the snapshot, then count the pad once more before it may go: an
	 * edit that came while the file was written is not in it, and the pad
	 * waits for another snapshot. Etherpad cannot hold a pad still, so an
	 * edit in the moment between this count and the delete is still lost;
	 * counting after the write keeps the write, the slowest step, out of
	 * that moment.
	 *
	 * @return TrashSnapshotMiss|true
	 * @throws RunBudgetSpentException
	 */
	private function writeAndRecount(ParsedPadFile $pad, PadSnapshot $snapshot): TrashSnapshotMiss|bool {
		$written = $this->write($pad, $snapshot);
		if ($written !== true) {
			return $written;
		}
		return $this->etherpadClient->getRevisionsCount($this->padId, RunBudget::timeoutOf($this->budget)) === $snapshot->revision
			? true
			: $this->missed(TrashSnapshotMiss::PadChanged);
	}

	/**
	 * A try that did not get the snapshot into the file, logged and handed
	 * back: a warning when it needs a look and is news, or is reported each
	 * time; debug otherwise.
	 *
	 * @param array<string,mixed> $cause
	 */
	private function missed(TrashSnapshotMiss $miss, array $cause = []): TrashSnapshotMiss {
		$message = 'A trashed .pad file did not get its snapshot. Its pad is kept for now.';
		$context = [...$this->context, ...$cause, 'reason' => $miss->value];
		if ($miss->needsALook() && ($this->news || $miss->reportedEachTime())) {
			$this->logger->warning($message, $context);
		} else {
			$this->logger->debug($message, $context);
		}
		return $miss;
	}

	/**
	 * The pad's text and HTML with the revision they belong to, or null when
	 * the pad changed while they were read: the count taken before them has
	 * to hold after.
	 */
	private function fetchStable(int $before): ?PadSnapshot {
		$text = $this->etherpadClient->getText($this->padId, RunBudget::timeoutOf($this->budget));
		$html = $this->etherpadClient->getHTML($this->padId, RunBudget::timeoutOf($this->budget));
		$after = $this->etherpadClient->getRevisionsCount($this->padId, RunBudget::timeoutOf($this->budget));
		return $before === $after ? new PadSnapshot($text, $html, $after) : null;
	}

	/**
	 * Write the snapshot into a file on its way to the trash, or in it.
	 *
	 * @return TrashSnapshotMiss|true
	 */
	private function write(ParsedPadFile $pad, PadSnapshot $snapshot): TrashSnapshotMiss|bool {
		try {
			if (($this->faultActive)(self::FAULT_WRITE_LOCK)) {
				throw new LockedException('Injected test fault: trash_write_lock');
			}
			if (($this->faultActive)(self::FAULT_WRITE_FAIL)) {
				throw new \RuntimeException('Injected test fault: trash_write_fail');
			}
			$this->file->putContent($this->padFileService->withExportSnapshot($pad, $snapshot));
			return true;
		} catch (LockedException) {
			return $this->missed(TrashSnapshotMiss::FileLocked);
		} catch (\Throwable $writeError) {
			return $this->missed(TrashSnapshotMiss::WriteFailed, SafeError::context($writeError));
		}
	}
}
