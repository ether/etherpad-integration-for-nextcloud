<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Util\PadAccessMode;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Files\File;
use OCP\Lock\LockedException;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * A .pad file gets its pad back: the file's own pad while Etherpad still
 * has it at the file's snapshot revision or later, a new pad from the
 * snapshot when it is gone or behind, and the row left waiting while
 * Etherpad cannot say. The pad id comes from the row, never from the file.
 *
 * Four ways in: a restore from the trash, which arrives at
 * LifecycleService (restore()); the sweep, for a file in Files whose row
 * still waits (settleWaitingFile()); an open of such a file
 * (settleOpenedFile()); and the recovery of a file that has no row at all
 * (recoverFromSnapshot()).
 */
class RestoreService {
	/** Etherpad refuses a longer pad name, measured against 2.x. */
	private const MAX_PAD_NAME_LENGTH = 50;
	/** Etherpad gave no answer for a waiting row's pad: the one reason a sweep counts as an outage, and a restore's event pass leaves (RestoreFromTrashListener). */
	public const REASON_PRESENCE_UNKNOWN = 'pad_presence_unknown';
	/** The file could not be read, so there was no revision to hold its pad to. */
	private const REASON_FILE_UNREADABLE = 'file_unreadable';
	/** A sweep let go of a row whose pad is not the file's; the file makes its own. */
	private const REASON_RELEASED = 'binding_released';
	/** The run had no time left for Etherpad once the file was read. */
	private const REASON_OUT_OF_TIME = 'run_budget_spent';
	/** An open could not remove what was left of a pad that is gone, so it kept the row for a later try. */
	private const REASON_LEFTOVER_KEPT = 'leftover_not_removed';
	/** The file moved while Etherpad was asked - deleted again, say - so its row is no longer the restore's to change. */
	private const REASON_FILE_MOVED = 'file_moved';

	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private ManagedPadLifecycle $padLifecycle,
		private AppConfigService $appConfig,
		private LoggerInterface $logger,
		private ISecureRandom $secureRandom,
		private ProvisionedPadRollback $provisionedPadRollback,
		private TestFaults $testFaults,
		private UserNodeResolver $userNodeResolver,
		private BoundPadResolver $boundPads,
	) {
	}

	/** @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string} */
	public function restore(File $file): array {
		$fileId = $file->getId();
		if (!PadFileType::isPad($file->getName())) {
			return LifecycleResult::skipped('not_pad_file', $fileId, $this->logger);
		}

		$binding = $this->findBindingForRestore($fileId);
		if ($binding === null) {
			// No row to settle. Whether to make a new pad is the setting's
			// call, as deleting the old one was.
			if (!$this->appConfig->isDeleteOnTrashEnabled()) {
				return LifecycleResult::skipped('delete_on_trash_disabled', $fileId, $this->logger);
			}
			return $this->restoreWithoutBinding($file, $fileId);
		}
		if (!$binding->isWaiting()) {
			return LifecycleResult::skipped('binding_not_pending_delete', $fileId, $this->logger);
		}
		// Settled whatever the setting says now: the file is back, and a row
		// left waiting would keep it from opening.
		return $this->settleWaitingBinding($file, $binding, mayReplace: true);
	}

	/**
	 * Settle the row of a file that is in Files while its row still waits:
	 * restore_pending, or pending_delete where no restore came. The decision
	 * a restore takes, except that a sweep writes no file. A pad that is gone
	 * or behind the snapshot releases the row instead, and the file offers
	 * its own recovery to whoever next opens it, through a node they can
	 * write.
	 *
	 * Etherpad is asked with what the run has left once the file is read;
	 * nothing left, and the row keeps its place. Only Etherpad's silence is
	 * Unanswered.
	 */
	public function settleWaitingFile(File $file, ?RunBudget $budget = null): SettleOutcome {
		if (!PadFileType::isPad($file->getName())) {
			return SettleOutcome::Left;
		}
		return $this->settleWaiting($file, $this->findBindingForRestore($file->getId()), null, $budget, inRequest: false);
	}

	/**
	 * The same decision, for an open of the file (SettleOnOpen), which
	 * someone waits for: on $binding, the row as the open read and checked
	 * it, so a row a trash has changed since is left to the trash; from
	 * $pad, the file as the open read it; and all of it within $budget, the
	 * clean-up after a pad that is gone included (releaseWaitingRow()).
	 */
	public function settleOpenedFile(File $file, Binding $binding, ParsedPadFile $pad, RunBudget $budget): SettleOutcome {
		if (!PadFileType::isPad($file->getName())) {
			return SettleOutcome::Left;
		}
		return $this->settleWaiting($file, $binding, $pad, $budget, inRequest: true);
	}

	private function settleWaiting(File $file, ?Binding $binding, ?ParsedPadFile $pad, ?RunBudget $budget, bool $inRequest): SettleOutcome {
		if ($binding === null || !$binding->isWaiting()) {
			return SettleOutcome::Left;
		}
		$result = $this->settleWaitingBinding($file, $binding, mayReplace: false, budget: $budget, read: $pad, inRequest: $inRequest);
		$reason = $result['reason'] ?? '';
		return match (true) {
			($result['status'] ?? '') === LifecycleResult::RESTORED, $reason === self::REASON_RELEASED => SettleOutcome::Settled,
			$reason === self::REASON_PRESENCE_UNKNOWN => SettleOutcome::Unanswered,
			default => SettleOutcome::Left,
		};
	}

	private function findBindingForRestore(int $fileId): ?Binding {
		try {
			return $this->bindingService->findByFileId($fileId);
		} catch (\Throwable $e) {
			throw LifecycleException::failed('Restore', $e);
		}
	}

	/**
	 * The row names the pad the file had before the trash, and that pad may
	 * hold its only current copy: the deletion was owed, not done, and the
	 * trash's snapshot may be older than the pad. The pad id comes from the
	 * row, never from the file: a pad id in a file is anyone's to write.
	 *
	 * The file is read first for its snapshot revision, unless $read is the
	 * file as its caller has just read it: a pad under that id with fewer
	 * revisions is not the pad the file knew.
	 *
	 * Asking Etherpad takes a while. Where no file lock holds the file
	 * meanwhile - file locking switched off, say - it can be deleted again,
	 * and a trash leaves a row that waits as it is, for it is no row of an
	 * active pad. So, whatever the answer, the row is changed only while
	 * the file is still where it was; moved, and the row is its trash's to
	 * finish. That holds for a file that could not be read too: one deleted
	 * before the read cannot be.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	private function settleWaitingBinding(File $file, Binding $binding, bool $mayReplace, ?RunBudget $budget = null, ?ParsedPadFile $read = null, bool $inRequest = false): array {
		$fileId = $file->getId();
		$path = $file->getPath();
		$padId = $binding->padId;
		$state = $binding->state;
		try {
			try {
				$pad = $read ?? $this->readRestoredPad($file);
			} catch (\Throwable $readError) {
				if ($this->userNodeResolver->hasMoved($fileId, $path)) {
					return LifecycleResult::skipped(self::REASON_FILE_MOVED, $fileId, $this->logger);
				}
				// No revision to hold the pad to, so no decision either.
				return $this->deferRestore($fileId, $padId, $state, $readError);
			}
			// As its row has it; an open hands the file over as it read it.
			$pad = $this->boundPads->followingRow($pad, $binding);
			$timeout = $budget?->nextCallTimeout();
			if ($budget !== null && $timeout === null) {
				// Reading the file took what the run had left.
				return LifecycleResult::skipped(self::REASON_OUT_OF_TIME, $fileId, $this->logger);
			}
			$presence = $this->padLifecycle->presenceOf($padId, $pad->snapshotRev, ['fileId' => $fileId], $timeout);
			if ($this->userNodeResolver->hasMoved($fileId, $path)) {
				return LifecycleResult::skipped(self::REASON_FILE_MOVED, $fileId, $this->logger);
			}
			return match ($presence) {
				PadPresence::Present => $this->resumeOwnPad($file, $fileId, $padId, $state, $mayReplace),
				PadPresence::Unknown => $this->deferRestore($fileId, $padId, $state),
				PadPresence::Absent, PadPresence::Behind => $mayReplace
					? $this->restoreWithReplacement($file, $path, $pad, $fileId, $padId, $state, $binding->accessMode, $presence)
					: $this->releaseWaitingRow($fileId, $padId, $state, $presence, $inRequest ? $budget : null),
			};
		} catch (LifecycleException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw LifecycleException::failed('Restore', $e);
		}
	}

	/** @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string} */
	private function resumeOwnPad(File $file, int $fileId, string $padId, string $fromState, bool $mayReplace): array {
		if ($this->bindingService->transition($fileId, $padId, $fromState, BindingService::STATE_ACTIVE)) {
			return LifecycleResult::restored($padId, $padId);
		}
		// Lost to the sweep finishing the trash: it took row and pad after
		// this restore asked about the pad, and wrote the pad's content into
		// the file first. The file makes a new pad from that, as a restore
		// without a binding does.
		if ($mayReplace && $this->bindingService->findByFileId($fileId) === null) {
			return $this->restoreWithoutBinding($file, $fileId);
		}
		return LifecycleResult::skipped('binding_state_transition_conflict', $fileId, $this->logger);
	}

	/**
	 * Etherpad could not be asked, or the file could not be read, so which
	 * pad is the file's is not known. Reactivating could bind the file to a
	 * pad that is gone or another one; replacing could give up the only
	 * current copy. The row waits for an answer.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	private function deferRestore(int $fileId, string $padId, string $fromState, ?\Throwable $readError = null): array {
		if ($fromState === BindingService::STATE_RESTORE_PENDING) {
			// The same state again moves only updated_at: a row that keeps
			// waiting goes to the back, behind the rows that may not.
			$this->bindingService->transition($fileId, $padId, $fromState, $fromState);
		} else {
			if (!$this->bindingService->transition($fileId, $padId, $fromState, BindingService::STATE_RESTORE_PENDING)) {
				return LifecycleResult::skipped('binding_state_transition_conflict', $fileId, $this->logger);
			}
			// Etherpad's silence was logged with its cause where it was met;
			// only an unreadable file is news here.
			$context = ['app' => 'etherpad_nextcloud', 'fileId' => $fileId];
			if ($readError === null) {
				$this->logger->info('Kept a restored file\'s pad for a later check.', $context);
			} else {
				$this->logger->warning('Could not read a restored .pad file. Kept its pad for a later check.', [...$context, ...SafeError::context($readError)]);
			}
		}
		return LifecycleResult::skipped($readError === null ? self::REASON_PRESENCE_UNKNOWN : self::REASON_FILE_UNREADABLE, $fileId, $this->logger);
	}

	/**
	 * The row's pad is not the file's any more: Etherpad has no such pad, or
	 * one with fewer revisions than the file's snapshot. The snapshot is all
	 * that is left of the file's pad, and a new pad is made from it, on the
	 * row as it was read.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	private function restoreWithReplacement(File $file, string $path, ParsedPadFile $pad, int $fileId, string $oldPadId, string $fromState, string $accessMode, PadPresence $presence): array {
		if (PadAccessMode::tryFrom($accessMode) === null) {
			// No pad can be made on this row. It goes, and the file offers
			// its own recovery, which goes by the file's access mode.
			$this->releaseReplacedRow($fileId, $oldPadId, $fromState);
			return LifecycleResult::skipped('unknown_access_mode', $fileId, $this->logger);
		}

		try {
			$result = $this->restoreOntoNewPad(
				$file,
				$path,
				$fileId,
				$pad,
				$accessMode,
				$oldPadId,
				'restore with replacement',
				fn (string $newPadId): bool => $this->claimForReplacement($fileId, $oldPadId, $fromState, $newPadId),
			);
		} catch (LifecycleException $e) {
			// Besides the new pad, a failure takes the row still naming the
			// old one: that pad is not the file's, and without a row the file
			// offers its own recovery.
			$this->releaseReplacedRow($fileId, $oldPadId, $fromState);
			throw $e;
		}

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
	private function claimForReplacement(int $fileId, string $oldPadId, string $fromState, string $newPadId): bool {
		try {
			return $this->bindingService->rebind($fileId, $oldPadId, $fromState, $newPadId, BindingService::STATE_ACTIVE);
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
	 * A row whose pad is no longer the file's, gone or behind its snapshot,
	 * left by a replacement that did not happen or by a sweep. Removed
	 * rather than kept waiting: a later check would reach the same answer,
	 * and without the row the file offers its own recovery. Conditional, so
	 * a row a trash or another restore has taken since stays as they left it.
	 */
	private function releaseReplacedRow(int $fileId, string $oldPadId, string $state): bool {
		try {
			if ($this->bindingService->deleteInState($fileId, $oldPadId, $state)) {
				return true;
			}
			// Gone with the replacement's rollback already, or taken by a
			// trash or another restore since - not this restore's either way.
			$this->logger->debug('Left a binding a failed restore no longer holds.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not release the binding of a restore that failed.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
		}
		return false;
	}

	/**
	 * The answer to a pad that is not the file's any more, where no file is
	 * written: the row goes, and the file makes its own pad from its
	 * snapshot when someone opens it. A pad that is gone takes what is left
	 * of it along, as after a replacement; one that is behind stays where it
	 * is.
	 *
	 * A sweep lets the row go first and clears up after it on the client's
	 * own timeouts: no one waits, and once the row is gone a call cut short
	 * by a budget would leave the group for good. An open ($openBudget),
	 * which someone waits for, clears up first, within its budget, and
	 * keeps the row when that does not finish (keepForLater()): once the
	 * row was gone, nothing would lead to the group.
	 *
	 * @return array{status: string, reason: string}
	 */
	private function releaseWaitingRow(int $fileId, string $padId, string $state, PadPresence $presence, ?RunBudget $openBudget): array {
		$gone = $presence === PadPresence::Absent;
		if ($gone && $openBudget !== null) {
			try {
				$this->padLifecycle->discardIfPresent($padId, $openBudget, knownAbsent: true, retried: true);
			} catch (\Throwable $e) {
				return $this->keepForLater($fileId, $padId, $state, $e);
			}
		}
		if (!$this->releaseReplacedRow($fileId, $padId, $state)) {
			return LifecycleResult::skipped('binding_state_transition_conflict', $fileId, $this->logger);
		}
		// The answer to an admin asking why a file suddenly wants recovering.
		$this->logger->info('Released the binding of a file whose pad is no longer its own. The file offers its own recovery.', [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'padId' => $padId,
		]);
		if ($gone && $openBudget === null) {
			$this->discardWhatIsLeftOf($fileId, $padId);
		}
		return LifecycleResult::skipped(self::REASON_RELEASED, $fileId, $this->logger);
	}

	/**
	 * The row an open keeps, touched as a row that waits again is: the next
	 * open within the minute leaves it (SettleOnOpen), and the sweep gets
	 * to it sooner. Time running out is to be expected, and a debug line; a
	 * failure of Etherpad's is worth one at info level, with its cause.
	 *
	 * @return array{status: string, reason: string}
	 */
	private function keepForLater(int $fileId, string $padId, string $state, \Throwable $cause): array {
		$this->bindingService->transition($fileId, $padId, $state, $state);
		$context = ['app' => 'etherpad_nextcloud', 'fileId' => $fileId, 'padId' => $padId, ...SafeError::context($cause)];
		$message = 'Could not remove what was left of a pad that is gone while opening its file. Its binding is kept for a later try.';
		if ($cause instanceof RunBudgetSpentException) {
			$this->logger->debug($message, $context);
		} else {
			$this->logger->info($message, $context);
		}
		return LifecycleResult::skipped(self::REASON_LEFTOVER_KEPT, $fileId, $this->logger);
	}

	/**
	 * A pad no row names any more - replaced, or released by a sweep - that
	 * Etherpad has said does not exist. A public one takes no call; for a
	 * protected pad its group can still be standing with nothing in it, and
	 * discardIfPresent() is what takes an empty group down.
	 * Best effort, after the row: the row is settled, and a group left over
	 * is garbage, not a way in - there is no pad in it for a session to
	 * open. On the client's own timeouts (releaseWaitingRow() says why).
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
		if ($this->testFaults->isActive(TestFaults::RESTORE_READ_LOCK)) {
			throw new LockedException('Injected test fault: restore_read_lock');
		}
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
			$this->padLifecycle->seed($newPadId, $snapshot['text'], $snapshot['html'], ['fileId' => $fileId]);
			$content = $this->padFileService->withRestoredSnapshot(
				$pad,
				$snapshot['text'],
				$snapshot['html'],
				$newPadId,
				$accessMode,
				$this->etherpadClient->buildPadUrl($newPadId),
				$this->revisionsOfSeededPad($newPadId),
			);
		} catch (\Throwable $e) {
			$this->provisionedPadRollback->discardUnlessBoundToFile($fileId, $newPadId, 'restore from snapshot');
			throw $e;
		}
		return [$newPadId, $content];
	}

	/**
	 * What the file records as synced: the new pad holds exactly its
	 * snapshot, and nobody else knows the pad's id yet to have changed it.
	 * Without an answer the file is left to its first sync, as a new one is.
	 */
	private function revisionsOfSeededPad(string $newPadId): int {
		try {
			return $this->etherpadClient->getRevisionsCount($newPadId);
		} catch (\Throwable) {
			return -1;
		}
	}

	/**
	 * The API's recovery of a file that has no row (docs/api-reference.md
	 * says when one needs it): the path a restore takes for such a file
	 * (restoreWithoutBinding), refused while the file has a row. The pad id
	 * the file names is never reused.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	public function recoverFromSnapshot(File $file): array {
		$fileId = $file->getId();
		if (!PadFileType::isPad($file->getName())) {
			throw new NotAPadFileException('File is not a .pad file.');
		}
		$binding = $this->bindingService->findByFileId($fileId);
		if ($binding !== null) {
			throw new PadAlreadyHasBindingException('A binding already exists for this file.');
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

	/** @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string} */
	private function restoreWithoutBinding(File $file, int $fileId): array {
		try {
			$pad = $this->readRestoredPad($file);
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
				// The file may still name the pad it named, in an older
				// version say: the row remembers it (BoundPadResolver).
				$this->bindingService->createBinding($fileId, $newPadId, $pad->accessMode, replacedPadId: $pad->padId);
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
	 * still names the old pad - while a row a trash took over keeps it.
	 *
	 * Seeding the new pad takes a while, so the file is asked once more
	 * before the claim, as settleWaitingBinding() asks it: moved - deleted
	 * again, say - and the new pad goes, with nothing claimed or written. A
	 * write through the old node would make a new file where it was.
	 *
	 * @param \Closure(string): bool $claim
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 * @throws LifecycleException
	 */
	private function restoreOntoNewPad(File $file, string $path, int $fileId, ParsedPadFile $pad, string $accessMode, string $oldPadId, string $flow, \Closure $claim): array {
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
			$this->writeRestoredContent($file, $updatedContent);
		} catch (\Throwable $e) {
			$this->provisionedPadRollback->removeMatchingBindingAndDiscard($fileId, $newPadId, $flow);
			throw LifecycleException::failed('Restore', $e);
		}
		return LifecycleResult::restored($oldPadId, $newPadId);
	}

	/** Make the pad a restored snapshot goes into; the names say so. */
	private function provisionRestorePadId(string $accessMode, string $oldPadId): string {
		return $this->padLifecycle->provisionFor(
			$accessMode,
			padId: fn (): string => $this->buildPublicRestorePadId($oldPadId),
			groupPadName: fn (): string => $this->buildProtectedRestorePadName(),
		);
	}

	private function writeRestoredContent(File $file, string $updatedContent): void {
		if ($this->testFaults->isActive(TestFaults::RESTORE_WRITE_LOCK)) {
			throw new LockedException('Injected test fault: restore_write_lock');
		}
		if ($this->testFaults->isActive(TestFaults::RESTORE_WRITE_FAIL)) {
			throw new \RuntimeException('Injected test fault: restore_write_fail');
		}
		$file->putContent($updatedContent);
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
