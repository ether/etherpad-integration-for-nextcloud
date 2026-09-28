<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Exception\PadFileNotWritableException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Util\PadAccessMode;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Files\File;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * A .pad file gets a pad from its own content when it has none any more.
 * The trash leaves a file's pad as it is, so a restore mostly finds it
 * there and does nothing. It makes a new pad from the file's snapshot for
 * a file without a row - its pad went with the trash of an earlier
 * version - and for one whose pad Etherpad lost while the file was away.
 * The pad id comes from the row, never from the file.
 *
 * Two ways in: a restore from the trash (restore(), from
 * RestoreFromTrashListener), and the recovery the API offers when an open
 * finds no pad (recoverFromSnapshot(), from PadLifecycleController).
 */
class RestoreService {
	/** Etherpad refuses a longer pad name, measured against 2.x. */
	private const MAX_PAD_NAME_LENGTH = 50;
	/** Etherpad gave no answer for a restored file's pad: the next open asks again. */
	public const REASON_PRESENCE_UNKNOWN = 'pad_presence_unknown';
	/** The file could not be read, so there was no revision to hold its pad to. */
	public const REASON_FILE_UNREADABLE = 'file_unreadable';
	/** A file back with an active row whose pad Etherpad has: taken back as it is. */
	private const REASON_PAD_PRESENT = 'pad_present';
	/** A public pad whose file holds no saved content: nothing to make a new pad from, whatever Etherpad has. */
	private const REASON_NOTHING_SAVED = 'nothing_saved';
	/** A file whose row names another pad than the file: the open's to settle. */
	private const REASON_ROW_NAMES_OTHER_PAD = 'row_names_other_pad';
	/** A file back without a row whose pad another file's row names: a copy, whose open offers the original. */
	private const REASON_COPY = 'copy_of_another_file';
	/** The file moved while its new pad was made - deleted again, say - so its row is no longer the restore's to change. */
	private const REASON_FILE_MOVED = 'file_moved';

	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private ManagedPadLifecycle $padLifecycle,
		private LoggerInterface $logger,
		private ISecureRandom $secureRandom,
		private ProvisionedPadRollback $provisionedPadRollback,
		private UserNodeResolver $userNodeResolver,
	) {
	}

	/** @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string} */
	public function restore(File $file): array {
		$fileId = $file->getId();
		if (!PadFileType::isPad($file->getName())) {
			return LifecycleResult::skipped('not_pad_file', $fileId, $this->logger);
		}

		try {
			$binding = $this->bindingService->findByFileId($fileId);
		} catch (\Throwable $e) {
			throw LifecycleException::failed('Restore', $e);
		}
		if ($binding === null) {
			return $this->restoreFileWithoutRow($file, $fileId);
		}
		if ($binding->state === BindingService::STATE_PENDING_DELETE) {
			// Seen deleted for good, yet back: the deletion did not happen.
			try {
				$this->bindingService->transition($fileId, $binding->padId, BindingService::STATE_PENDING_DELETE, BindingService::STATE_ACTIVE);
			} catch (\Throwable $e) {
				throw LifecycleException::failed('Restore', $e);
			}
		}
		return $this->restoreActiveRow($file, $fileId, $binding);
	}

	/**
	 * The row's pad is not the file's any more: Etherpad has no such pad, or
	 * one with fewer revisions than the file's snapshot. The snapshot is all
	 * that is left of the file's pad, and a new pad is made from it, on the
	 * row as it was read.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	private function restoreWithReplacement(File $file, string $path, ParsedPadFile $pad, int $fileId, string $oldPadId, string $accessMode, PadPresence $presence): array {
		if (PadAccessMode::tryFrom($accessMode) === null) {
			// No pad can be made on this row, a record the app did not write.
			// It stays as it is, as a row does on any failure here.
			return LifecycleResult::skipped('unknown_access_mode', $fileId, $this->logger);
		}

		// A failure leaves the row on the lost pad, moved back onto it if it
		// was claimed already: the pad is the file's, only lost, and the
		// next open offers the new pad again - a failure that passes,
		// Etherpad gone while seeding or the file locked, costs nothing.
		$result = $this->restoreOntoNewPad(
			$file,
			$path,
			$fileId,
			$pad,
			$accessMode,
			$oldPadId,
			'restore with replacement',
			fn (string $newPadId): bool => $this->claimForReplacement($fileId, $oldPadId, $newPadId),
			rowStays: true,
		);

		// Outside the try on purpose: the restore is done and recorded, and
		// nothing about clearing up after it may turn that into a failure.
		if ($result['status'] === LifecycleResult::RESTORED && $presence === PadPresence::Absent) {
			$this->discardWhatIsLeftOf($fileId, $oldPadId);
		}
		return $result;
	}

	/**
	 * Point the row at the replacement, if it still names the old pad in
	 * the state it was read in. Three outcomes, kept apart: true when this
	 * restore holds the row, false when another flow moved it first, and an
	 * exception when neither can be told - which the caller treats as the
	 * failure it is, so the file is not written on a claim nobody has seen.
	 *
	 * An update can commit and still fail to say so, so a throw is settled
	 * by asking the row. Only a row that names the replacement settles it as
	 * claimed; one that names another pad, or cannot be read, leaves the
	 * claim's own error standing.
	 */
	private function claimForReplacement(int $fileId, string $oldPadId, string $newPadId): bool {
		try {
			return $this->bindingService->rebind($fileId, $oldPadId, BindingService::STATE_ACTIVE, $newPadId, BindingService::STATE_ACTIVE);
		} catch (\Throwable $claimError) {
			try {
				if ($this->bindingService->isBoundTo($fileId, $newPadId)) {
					return true;
				}
			} catch (\Throwable) {
				// No answer either way; the claim's own error says why.
			}
			throw $claimError;
		}
	}

	/**
	 * A pad no row names any more, replaced, that Etherpad has said does not
	 * exist. A public one takes no call; for a protected pad its group can
	 * still be standing with nothing in it, and discardIfPresent() is what
	 * takes an empty group down. Best effort, after the row: the row is
	 * settled, and a group left over is garbage, not a way in - there is no
	 * pad in it for a session to open. On the client's own timeouts: no one
	 * waits for it.
	 */
	private function discardWhatIsLeftOf(int $fileId, string $padId): void {
		try {
			$this->padLifecycle->discardIfPresent($padId, knownAbsent: true);
		} catch (\Throwable $e) {
			// No row names the pad now, so nothing else leads here.
			$this->logger->warning('Could not remove what was left of a pad that is gone.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				...SafeError::context($e),
			]);
		}
	}

	/** The `.pad` back from the trash, as a restore from its snapshot reads it. */
	private function readRestoredPad(File $file): ParsedPadFile {
		return $this->padFileService->readPad($file->getContent());
	}

	/**
	 * A new pad holding the file's snapshot, and the `.pad` content naming
	 * it. A failure here removes the pad here; no row names it yet.
	 *
	 * @return array{string,string} the new pad's id, and the content that names it
	 */
	private function seedFromSnapshot(int $fileId, ParsedPadFile $pad, string $accessMode, string $oldPadId): array {
		$snapshot = $this->padFileService->getSnapshotPartsFromBody($pad->body);
		$newPadId = $this->provisionRestorePadId($accessMode, $oldPadId);
		try {
			// Nobody else knows the new pad's id yet to have changed it, so
			// the file records what seeding left as synced.
			$revisions = $this->padLifecycle->seed($newPadId, $snapshot['text'], $snapshot['html'], ['fileId' => $fileId]);
			$content = $this->padFileService->withRestoredSnapshot(
				$pad,
				$snapshot['text'],
				$snapshot['html'],
				$newPadId,
				$this->etherpadClient->buildPadUrl($newPadId),
				$revisions,
			);
		} catch (\Throwable $e) {
			$this->provisionedPadRollback->discardUnlessBoundToFile($fileId, $newPadId, 'restore from snapshot');
			throw $e;
		}
		return [$newPadId, $content];
	}

	/**
	 * The API's recovery of a file from its own content (docs/api-reference.md
	 * says when one needs it). A file without a row takes the path a
	 * restore takes for such a file (restoreWithoutBinding); a file whose
	 * row names a pad Etherpad has lost, the path a restore takes for a row
	 * whose pad is gone (recoverLostPad). Refused for any other row. The
	 * pad id the file names is never reused.
	 *
	 * Refused, before Etherpad is asked, to whoever may not change the
	 * file - a read-only share, say: a recovery writes the file, and on a
	 * row it moves the row first. The open offers it to a reader of a file
	 * without a row too, which the card cannot tell apart, and the
	 * endpoint answers anyone who can see the file.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 * @throws PadFileNotWritableException
	 */
	public function recoverFromSnapshot(File $file): array {
		$fileId = $file->getId();
		if (!PadFileType::isPad($file->getName())) {
			throw new NotAPadFileException('File is not a .pad file.');
		}
		if (!$file->isUpdateable()) {
			throw new PadFileNotWritableException('The user may not change this .pad file.');
		}
		$binding = $this->bindingService->findByFileId($fileId);
		if ($binding !== null) {
			return $this->recoverLostPad($file, $fileId, $binding);
		}
		$result = $this->restoreWithoutBinding($file, $fileId);
		if (($result['status'] ?? '') === LifecycleResult::RESTORED) {
			$this->logger->info('Pad recovered from snapshot.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
			]);
		}
		return $result;
	}

	/**
	 * The API's recovery of an active row whose pad Etherpad has lost
	 * (ManagedPadLifecycle::howLost()): a new pad from the file's content,
	 * the row moved onto it. Asked here again, not taken from the open that
	 * sent the user: the answer must hold for the row as it is now. A row
	 * that waits, one naming another pad than the file, or one whose pad
	 * Etherpad has, is refused; Etherpad's own trouble reaches the caller as
	 * it is.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 * @throws PadAlreadyHasBindingException
	 * @throws LifecycleException
	 * @throws EtherpadClientException
	 */
	private function recoverLostPad(File $file, int $fileId, Binding $binding): array {
		if ($binding->state !== BindingService::STATE_ACTIVE) {
			throw new PadAlreadyHasBindingException('A binding already exists for this file.');
		}
		try {
			$found = $this->lostPadOf($file, $binding);
		} catch (EtherpadClientException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw LifecycleException::failed('Restore', $e);
		}
		if (is_string($found)) {
			throw new PadAlreadyHasBindingException('A binding already exists for this file.');
		}
		return $this->replaceLostPad($file, $fileId, $binding, $found[0], $found[1]);
	}

	/**
	 * A file back from the trash whose row stayed active: its pad is taken
	 * back as it is - unless Etherpad has lost it while the file was away.
	 * Then the file gets a new pad from its content at once, as an open
	 * would only offer: coming back from the trash, the file is surely the
	 * one the pad was. Etherpad not answering, or a file that cannot be
	 * read, leave the row as it is, and the next open asks again - and,
	 * with the pad lost, offers the new one.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 * @throws LifecycleException when Etherpad refuses to say
	 */
	private function restoreActiveRow(File $file, int $fileId, Binding $binding): array {
		try {
			$found = $this->lostPadOf($file, $binding);
		} catch (\Throwable $e) {
			if (EtherpadClientException::isEtherpadUnreachable($e)) {
				return LifecycleResult::skipped(self::REASON_PRESENCE_UNKNOWN, $fileId, $this->logger);
			}
			if ($e instanceof EtherpadClientException) {
				throw LifecycleException::failed('Restore', $e);
			}
			// The open reads the file again, and says what is wrong with it.
			return LifecycleResult::skipped(self::REASON_FILE_UNREADABLE, $fileId, $this->logger);
		}
		if (is_string($found)) {
			return LifecycleResult::skipped($found, $fileId, $this->logger);
		}
		return $this->replaceLostPad($file, $fileId, $binding, $found[0], $found[1]);
	}

	/**
	 * Whether Etherpad has lost the pad of the file's active row: the file
	 * as read and how the pad is lost, or why the file is no case for a new
	 * pad - it names another pad than its row (or one on another server),
	 * it is a public pad whose file holds nothing saved, or Etherpad has
	 * the pad.
	 *
	 * @return array{ParsedPadFile, PadPresence}|string
	 * @throws \Throwable reading the file, or asking Etherpad
	 */
	private function lostPadOf(File $file, Binding $binding): array|string {
		$pad = $this->readRestoredPad($file);
		if ($pad->isExternal || $pad->padId !== $binding->padId) {
			return self::REASON_ROW_NAMES_OTHER_PAD;
		}
		if (!ManagedPadLifecycle::holdsSavedContent($binding->accessMode, $pad->snapshotRev, $pad->savedText)) {
			return self::REASON_NOTHING_SAVED;
		}
		$lost = $this->padLifecycle->howLost($binding->padId, $binding->accessMode, $pad->snapshotRev, $pad->savedText);
		return $lost === null ? self::REASON_PAD_PRESENT : [$pad, $lost];
	}

	/**
	 * A file back from the trash without a row: its pad went with the trash
	 * of an earlier version, so it gets one from its content whatever the
	 * setting says now - nothing is left to keep. Unless another file's row
	 * names the pad: then this is a copy, never opened, whose pad lives on
	 * with the original, and its open offers the choice, the original or a
	 * pad of its own.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 * @throws LifecycleException
	 */
	private function restoreFileWithoutRow(File $file, int $fileId): array {
		try {
			$pad = $this->readRestoredPad($file);
		} catch (\Throwable) {
			// A legacy Ownpad link without metadata, or a file that cannot be
			// read now: the open reads it again, and migrates it, offers its
			// recovery or says what is wrong - as for an active row.
			return LifecycleResult::skipped(self::REASON_FILE_UNREADABLE, $fileId, $this->logger);
		}
		try {
			$original = $this->bindingService->findByPadId($pad->padId);
		} catch (\Throwable $e) {
			throw LifecycleException::failed('Restore', $e);
		}
		if ($original !== null && $original->fileId !== $fileId) {
			return LifecycleResult::skipped(self::REASON_COPY, $fileId, $this->logger);
		}
		return $this->restoreWithoutBinding($file, $fileId, $pad);
	}

	/**
	 * The file's new pad in place of the one Etherpad lost: what a restore
	 * does for a row whose pad is gone (restoreWithReplacement()).
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 * @throws LifecycleException
	 */
	private function replaceLostPad(File $file, int $fileId, Binding $binding, ParsedPadFile $pad, PadPresence $lost): array {
		$result = $this->restoreWithReplacement($file, $file->getPath(), $pad, $fileId, $binding->padId, $binding->accessMode, $lost);
		if (($result['status'] ?? '') === LifecycleResult::RESTORED) {
			$this->logger->info('A pad Etherpad had lost was made anew from its file.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $binding->padId,
			]);
		}
		return $result;
	}

	/** @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string} */
	private function restoreWithoutBinding(File $file, int $fileId, ?ParsedPadFile $read = null): array {
		try {
			$pad = $read ?? $this->readRestoredPad($file);
		} catch (\Throwable $e) {
			throw LifecycleException::failed('Restore', $e);
		}
		if ($pad->namesAnExternalPad()) {
			return LifecycleResult::skipped('external_pad', $fileId, $this->logger);
		}
		// The unique constraint on file_id is the claim: of two recoveries of
		// one file, the second's createBinding throws, and it writes nothing
		// over the first's content.
		return $this->restoreOntoNewPad(
			$file,
			$file->getPath(),
			$fileId,
			$pad,
			$pad->accessMode,
			$pad->padId,
			'restore without binding',
			function (string $newPadId) use ($fileId, $pad): bool {
				$this->bindingService->createBinding($fileId, $newPadId, $pad->accessMode);
				return true;
			},
		);
	}

	/**
	 * The file restored onto a new pad made from its snapshot: what both
	 * restores from a snapshot share. The row is claimed for the new pad
	 * before the file is touched ($claim, true when this restore holds the
	 * row): whoever loses the claim has written nothing, and so has nothing
	 * to put back over someone else's content. After the claim the only
	 * step left is the write, so there is no file to roll back either.
	 *
	 * A claim lost to another flow leaves the row and the file to it. One
	 * that fails, or a write that fails, takes the new pad down, and an
	 * active row naming it with it - that row would contradict a `.pad` that
	 * still names the old pad - while a row seen deleted for good meanwhile
	 * keeps it. With $rowStays, the row a replacement claimed is moved back
	 * onto the old pad instead, where the file still points; only when that
	 * fails too does it go. A write that failed and a file that cannot be
	 * read afterwards leave open which pad the file names, so no row may be
	 * left to contradict it: the row goes with the new pad, which holds
	 * nothing the file does not, and the file's next open offers a pad from
	 * its content, whichever pad it names.
	 *
	 * Seeding the new pad takes a while, so the file is asked once more
	 * before the claim: moved - deleted again, say - and the new pad goes,
	 * with nothing claimed or written. A write through the old node would
	 * make a new file where it was.
	 *
	 * @param \Closure(string): bool $claim
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 * @throws LifecycleException
	 */
	private function restoreOntoNewPad(File $file, string $path, int $fileId, ParsedPadFile $pad, string $accessMode, string $oldPadId, string $flow, \Closure $claim, bool $rowStays = false): array {
		try {
			[$newPadId, $updatedContent] = $this->seedFromSnapshot($fileId, $pad, $accessMode, $oldPadId);
		} catch (\Throwable $e) {
			throw LifecycleException::failed('Restore', $e);
		}

		try {
			$moved = $this->userNodeResolver->hasMoved($fileId, $path);
		} catch (\Throwable $e) {
			$this->provisionedPadRollback->discardUnlessBoundToFile($fileId, $newPadId, $flow);
			throw LifecycleException::failed('Restore', $e);
		}
		if ($moved) {
			$this->provisionedPadRollback->discardUnlessBoundToFile($fileId, $newPadId, $flow);
			return LifecycleResult::skipped(self::REASON_FILE_MOVED, $fileId, $this->logger);
		}

		try {
			if (!$claim($newPadId)) {
				$this->provisionedPadRollback->discardUnlessBoundToFile($fileId, $newPadId, $flow);
				return LifecycleResult::skipped('binding_state_transition_conflict', $fileId, $this->logger);
			}
			$file->putContent($updatedContent);
		} catch (\Throwable $e) {
			$names = $this->fileNames($file, $newPadId);
			if ($names === true) {
				// The write landed, and something after it failed - a hook,
				// say. Row and file both name the new pad: taking either back
				// would leave them naming different pads.
				$this->logger->warning('A restored .pad file reported a failed write, yet names its new pad; the new pad is kept.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					...SafeError::context($e),
				]);
				return LifecycleResult::restored($oldPadId, $newPadId);
			}
			if ($names === null) {
				$this->logger->warning('A restored .pad file reported a failed write and cannot be read; its row is removed, and its next open offers a new pad.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					...SafeError::context($e),
				]);
				$this->provisionedPadRollback->removeMatchingBindingAndDiscard($fileId, $newPadId, $flow);
				throw LifecycleException::failed('Restore', $e);
			}
			if ($rowStays && $this->moveRowBack($fileId, $newPadId, $oldPadId)) {
				$this->provisionedPadRollback->discardUnlessBoundToFile($fileId, $newPadId, $flow);
			} else {
				$this->provisionedPadRollback->removeMatchingBindingAndDiscard($fileId, $newPadId, $flow);
			}
			throw LifecycleException::failed('Restore', $e);
		}
		return LifecycleResult::restored($oldPadId, $newPadId);
	}

	/** Whether the file names this pad now; null when that cannot be read either. */
	private function fileNames(File $file, string $padId): ?bool {
		try {
			return $this->padFileService->readPad($file->getContent())->padId === $padId;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * The active row a failed replacement claimed, back on the old pad.
	 * False when it does not name the new pad - the claim never landed -
	 * or cannot be moved.
	 */
	private function moveRowBack(int $fileId, string $newPadId, string $oldPadId): bool {
		try {
			return $this->bindingService->rebind($fileId, $newPadId, BindingService::STATE_ACTIVE, $oldPadId, BindingService::STATE_ACTIVE);
		} catch (\Throwable) {
			return false;
		}
	}

	/** Make the pad a restored snapshot goes into; the names say so. */
	private function provisionRestorePadId(string $accessMode, string $oldPadId): string {
		return $this->padLifecycle->provisionFor(
			$accessMode,
			padId: fn (): string => $this->buildPublicRestorePadId($oldPadId),
			groupPadName: fn (): string => $this->buildProtectedRestorePadName(),
		);
	}

	/**
	 * `r-<base>-<suffix>`, within the 50 characters Etherpad allows a pad
	 * name. The base is the old id without an earlier restore's `r-` and
	 * suffix, so a pad restored again does not grow by them each time, and
	 * is cut to what is left. `$` stays out: in a public id Etherpad refuses
	 * it, since it marks a group pad.
	 */
	private function buildPublicRestorePadId(string $oldPadId): string {
		$suffix = $this->secureRandom->generate(12, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$base = preg_replace('/^r-(.+)-[a-z0-9]{12}$/', '$1', $oldPadId) ?? $oldPadId;
		$normalized = trim(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $base) ?? '', '-');
		$normalized = rtrim(substr($normalized, 0, self::MAX_PAD_NAME_LENGTH - strlen('r--') - strlen($suffix)), '-');
		return 'r-' . ($normalized === '' ? 'pad' : $normalized) . '-' . $suffix;
	}

	private function buildProtectedRestorePadName(): string {
		$suffix = $this->secureRandom->generate(14, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		return 'restored-' . $suffix;
	}
}
