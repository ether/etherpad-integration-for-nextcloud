<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * The deletions a trash owed, carried out: for a file in its owner's trash,
 * its snapshot first, then row and pad; for a file gone for good, pad and
 * row. An undecided restore whose file is gone for good ends the same way.
 *
 * For the sweep, one row at a time under its lock on the row
 * (PendingBindingService::whileHeld()). Each call answers how the row was
 * left (SettleOutcome), which the sweep counts against its run. Each asks
 * whether deleting on trash is switched on when it comes to it, and does
 * nothing while it is off: a setting switched off during a run stops there.
 */
class OwedDeletions {
	public function __construct(
		private BindingService $bindingService,
		private AppConfigService $appConfig,
		private ManagedPadLifecycle $padLifecycle,
		private TrashSnapshotWriters $snapshotWriters,
		private UserNodeResolver $userNodeResolver,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The second half of a trash that could not write its snapshot: the file
	 * sits in its owner's trash now, no longer locked by its delete. The
	 * pad's content goes into it, then the row goes, then the pad - a
	 * restore after that makes a new pad from this snapshot.
	 *
	 * Held to the file's snapshot revision like a restore: a pad behind it
	 * is not the file's and a pad that is gone has nothing to give, so the
	 * row is released and the file keeps the snapshot it has. Etherpad
	 * giving no answer, to the question or while the snapshot is read, is
	 * Unanswered. A file that did not get its snapshot waits as its
	 * TrashSnapshotMiss says; whether its trouble is news is
	 * Binding::untouchedSinceOwed().
	 */
	public function finishTrash(File $file, RunBudget $budget): SettleOutcome {
		if (!$this->appConfig->isDeleteOnTrashEnabled()) {
			return SettleOutcome::Left;
		}
		$fileId = $file->getId();
		$binding = $this->bindingService->findByFileId($fileId);
		if ($binding === null || $binding->state !== BindingService::STATE_PENDING_DELETE) {
			return SettleOutcome::Left;
		}
		$padId = $binding->padId;
		$snapshots = $this->snapshotWriters->for($file, $padId, news: $binding->untouchedSinceOwed());
		$pad = $snapshots->read();
		if ($pad instanceof TrashSnapshotMiss) {
			return $this->waitAgain($fileId, $padId, $pad);
		}

		$timeout = $budget->nextCallTimeout();
		if ($timeout === null) {
			// Reading the file took what the run had left.
			return SettleOutcome::Left;
		}
		$probe = $this->padLifecycle->probe($padId, $pad->snapshotRev, ['fileId' => $fileId], $timeout);
		if ($probe->presence === PadPresence::Unknown) {
			return SettleOutcome::Unanswered;
		}
		if ($probe->presence === PadPresence::Behind) {
			return $this->bindingService->deleteInState($fileId, $padId, BindingService::STATE_PENDING_DELETE)
				? SettleOutcome::Settled
				: SettleOutcome::Left;
		}
		if ($probe->presence === PadPresence::Present) {
			// Read before the write; hasMoved() says why.
			$path = $file->getPath();
			try {
				$written = $snapshots->writeInTrash($pad, $probe->revisions, $budget, fn (): bool => $this->userNodeResolver->hasMoved($fileId, $path));
			} catch (RunBudgetSpentException) {
				return SettleOutcome::Left;
			}
			if ($written === TrashSnapshotMiss::SnapshotNotFetched) {
				return SettleOutcome::Unanswered;
			}
			if ($written === TrashSnapshotMiss::FileMovedWhileWritten) {
				$this->removeStrayCopy($fileId, $path);
			}
			if ($written instanceof TrashSnapshotMiss) {
				return $this->waitAgain($fileId, $padId, $written);
			}
		}
		return $this->deleteOwed($fileId, $padId, BindingService::STATE_PENDING_DELETE, $probe->presence, $budget, claimFirst: true);
	}

	/**
	 * A deletion owed for a file that is gone for good: nothing is left in
	 * the file cache under its id, in Files or in any trash. Etherpad is
	 * asked first, so one that does not answer costs one call; then the pad
	 * goes, then the row.
	 */
	public function finishGoneFile(WaitingBinding $row, RunBudget $budget): SettleOutcome {
		if (!$this->appConfig->isDeleteOnTrashEnabled()) {
			return SettleOutcome::Left;
		}
		$presence = $this->padLifecycle->presenceOf($row->padId, -1, ['fileId' => $row->fileId], $budget->callTimeout());
		if ($presence === PadPresence::Unknown) {
			return SettleOutcome::Unanswered;
		}
		return $this->deleteOwed($row->fileId, $row->padId, $row->state, $presence, $budget, claimFirst: false);
	}

	/**
	 * The last step of a deletion owed: row and pad.
	 *
	 * $claimFirst, for a file in a trash: the row goes first. It is what a
	 * restore takes the pad back by, and whichever of the two moves it
	 * first has the pad - the other leaves it be. Not started when no call
	 * would fit in the run any more. Once the row is gone the pad goes
	 * whatever the run has left, each call bounded by the client's own
	 * timeout: stopping between the two calls a group pad takes would
	 * leave the group for good. A pad that still cannot be deleted is left
	 * over, and nothing leads to it: it is logged with its id. Its content
	 * is in the file.
	 *
	 * Without it, for a file gone for good, the pad goes first. No restore
	 * can come for that file, and a pad that could not be deleted keeps its
	 * row, so the next run tries again.
	 */
	private function deleteOwed(int $fileId, string $padId, string $state, PadPresence $presence, RunBudget $budget, bool $claimFirst): SettleOutcome {
		if ($claimFirst && ($budget->nextCallTimeout() === null || !$this->bindingService->deleteInState($fileId, $padId, $state))) {
			return SettleOutcome::Left;
		}
		try {
			$this->padLifecycle->discardIfPresent($padId, $claimFirst ? null : $budget, knownAbsent: $presence === PadPresence::Absent);
		} catch (\Throwable $e) {
			$context = ['app' => 'etherpad_nextcloud', 'fileId' => $fileId, ...SafeError::context($e)];
			if ($claimFirst) {
				$this->logger->warning('Could not delete a pad whose binding is gone. It is left over.', [...$context, 'padId' => $padId]);
				return SettleOutcome::Settled;
			}
			if ($e instanceof RunBudgetSpentException) {
				return SettleOutcome::Left;
			}
			$this->logger->warning('Could not delete the pad of a file that is gone for good. Its row stays for the next run.', $context);
			return SettleOutcome::Unanswered;
		}
		if (!$claimFirst) {
			// The result is not checked: a file with no file cache row does
			// not come back, so no other flow races for this row.
			$this->bindingService->deleteInState($fileId, $padId, $state);
		}
		return SettleOutcome::Settled;
	}

	/**
	 * The copy a write left where the file was, removed. One that stays is
	 * only logged: the pad holds its content.
	 */
	private function removeStrayCopy(int $fileId, string $path): void {
		try {
			$this->userNodeResolver->removeStrayCopy($fileId, $path);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove the copy a sweep wrote into the trash after its file was restored.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * A row left for a later run. The file's own trouble moves it to the
	 * back of the queue, its deleted_at kept; what passes by itself is
	 * tried again where the row is.
	 */
	private function waitAgain(int $fileId, string $padId, TrashSnapshotMiss $miss): SettleOutcome {
		if ($miss->movesTheRowBack()) {
			$this->bindingService->transition($fileId, $padId, BindingService::STATE_PENDING_DELETE, BindingService::STATE_PENDING_DELETE);
		}
		return SettleOutcome::Left;
	}
}
