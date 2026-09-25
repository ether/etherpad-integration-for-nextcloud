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
 * (debug); a sweep reads it off the row.
 */
final class TrashSnapshotWriter {
	/** @var array<string,mixed> */
	private array $context;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private PadFileService $padFileService,
		private LoggerInterface $logger,
		private TestFaults $testFaults,
		private File $file,
		private string $padId,
		private bool $news = true,
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
			if ($this->testFaults->isActive(TestFaults::TRASH_READ_LOCK)) {
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
	 * trash never fails on it; one on the count after the write is its own
	 * miss. There is no run to keep to: each Etherpad call gets the
	 * client's own timeout.
	 */
	public function writeAtTrash(ParsedPadFile $pad): bool {
		try {
			return $this->take($pad, null, null, null) === true;
		} catch (\Throwable $fetchError) {
			$this->missed(TrashSnapshotMiss::SnapshotNotFetched, SafeError::context($fetchError));
			return false;
		}
	}

	/**
	 * Take the pad's current content into the file in the trash, for the
	 * sweep: true once the file holds it, or the miss. Etherpad giving no
	 * answer is the miss SnapshotNotFetched, or PadNotRecounted after the
	 * write; a spent budget, and any other error, goes to the caller.
	 *
	 * $revisions: the pad's count, when the caller has just asked for it.
	 * $budget: the sweep's run, whose rest each Etherpad call gets.
	 * $moved: whether a restore has taken the file back since it was read,
	 * asked right before the write and once more after it (writeAndRecount()).
	 *
	 * @param \Closure(): bool $moved
	 * @return TrashSnapshotMiss|true
	 * @throws RunBudgetSpentException
	 */
	public function writeInTrash(ParsedPadFile $pad, ?int $revisions, RunBudget $budget, \Closure $moved): TrashSnapshotMiss|bool {
		try {
			return $this->take($pad, $revisions, $budget, $moved);
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
	private function take(ParsedPadFile $pad, ?int $revisions, ?RunBudget $budget, ?\Closure $moved): TrashSnapshotMiss|bool {
		$snapshot = $this->fresh($pad, $revisions, $budget);
		if (!$snapshot instanceof PadSnapshot) {
			return $snapshot;
		}
		if ($moved !== null && $moved()) {
			return $this->missed(TrashSnapshotMiss::FileMoved);
		}
		return $this->writeAndRecount($pad, $snapshot, $budget, $moved);
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
	private function fresh(ParsedPadFile $pad, ?int $revisions, ?RunBudget $budget): PadSnapshot|TrashSnapshotMiss|bool {
		$revisions ??= $this->etherpadClient->getRevisionsCount($this->padId, RunBudget::timeoutOf($budget));
		if ($revisions === $pad->snapshotRev) {
			return true;
		}
		if ($revisions < $pad->snapshotRev) {
			return $this->missed(TrashSnapshotMiss::PadBehind);
		}
		return $this->fetchStable($revisions, $budget) ?? $this->missed(TrashSnapshotMiss::PadChanged);
	}

	/**
	 * Write the snapshot, then count the pad once more before it may go: an
	 * edit that came while the file was written is not in it, and the pad
	 * waits for another snapshot. Etherpad cannot hold a pad still, so an
	 * edit in the moment between this count and the delete is still lost;
	 * counting after the write keeps the write, the slowest step, out of
	 * that moment.
	 *
	 * Then, for the sweep, whether the file moved while it was written, also
	 * when the count gets no answer or no time: if so the pad stays, and the
	 * caller has a copy to clear. Otherwise a count Etherpad does not answer
	 * is the miss PadNotRecounted - the snapshot is written, only whether
	 * the pad moved on is not known - and any other error goes on as it is.
	 * Asked after the count, so that less gets past both questions; what
	 * still does, and what would close it, is in docs/architecture.md.
	 *
	 * @param ?\Closure(): bool $moved
	 * @return TrashSnapshotMiss|true
	 * @throws RunBudgetSpentException
	 */
	private function writeAndRecount(ParsedPadFile $pad, PadSnapshot $snapshot, ?RunBudget $budget, ?\Closure $moved): TrashSnapshotMiss|bool {
		$written = $this->write($pad, $snapshot);
		if ($written !== true) {
			return $written;
		}
		try {
			$unchanged = $this->etherpadClient->getRevisionsCount($this->padId, RunBudget::timeoutOf($budget)) === $snapshot->revision;
		} catch (EtherpadClientException $countError) {
			return $this->movedWhileWritten($moved) ?? $this->missed(TrashSnapshotMiss::PadNotRecounted, SafeError::context($countError));
		} catch (RunBudgetSpentException $spent) {
			return $this->movedWhileWritten($moved) ?? throw $spent;
		}
		return $this->movedWhileWritten($moved) ?? ($unchanged ? true : $this->missed(TrashSnapshotMiss::PadChanged));
	}

	/** @param ?\Closure(): bool $moved */
	private function movedWhileWritten(?\Closure $moved): ?TrashSnapshotMiss {
		return $moved !== null && $moved() ? $this->missed(TrashSnapshotMiss::FileMovedWhileWritten) : null;
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
	private function fetchStable(int $before, ?RunBudget $budget): ?PadSnapshot {
		$text = $this->etherpadClient->getText($this->padId, RunBudget::timeoutOf($budget));
		$html = $this->etherpadClient->getHTML($this->padId, RunBudget::timeoutOf($budget));
		$after = $this->etherpadClient->getRevisionsCount($this->padId, RunBudget::timeoutOf($budget));
		return $before === $after ? new PadSnapshot($text, $html, $after) : null;
	}

	/**
	 * Write the snapshot into a file on its way to the trash, or in it.
	 *
	 * @return TrashSnapshotMiss|true
	 */
	private function write(ParsedPadFile $pad, PadSnapshot $snapshot): TrashSnapshotMiss|bool {
		try {
			if ($this->testFaults->isActive(TestFaults::TRASH_WRITE_LOCK)) {
				throw new LockedException('Injected test fault: trash_write_lock');
			}
			if ($this->testFaults->isActive(TestFaults::TRASH_WRITE_FAIL)) {
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
