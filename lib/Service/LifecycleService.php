<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingStateConflictException;
use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Util\PadAccessMode;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Lock\LockedException;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class LifecycleService {
	public const RESULT_TRASHED = 'trashed';
	public const RESULT_RESTORED = 'restored';
	public const RESULT_SKIPPED = 'skipped';
	public const TEST_FAULT_TRASH_READ_LOCK = 'trash_read_lock';
	public const TEST_FAULT_TRASH_WRITE_LOCK = 'trash_write_lock';
	public const TEST_FAULT_TRASH_WRITE_FAIL = 'trash_write_fail';
	public const TEST_FAULT_RESTORE_READ_LOCK = 'restore_read_lock';
	public const TEST_FAULT_RESTORE_WRITE_LOCK = 'restore_write_lock';
	public const TEST_FAULT_RESTORE_WRITE_FAIL = 'restore_write_fail';
	/** Etherpad refuses a longer pad name, measured against 2.x. */
	private const MAX_PAD_NAME_LENGTH = 50;
	/** Etherpad gave no answer for a waiting row's pad: the one reason a sweep counts as an outage. */
	private const REASON_PRESENCE_UNKNOWN = 'pad_presence_unknown';
	/** The file could not be read, so there was no revision to hold its pad to. */
	private const REASON_FILE_UNREADABLE = 'file_unreadable';
	/** A sweep let go of a row whose pad is not the file's; the file makes its own. */
	private const REASON_RELEASED = 'binding_released';

	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private ManagedPadLifecycle $padLifecycle,
		private IConfig $config,
		private LoggerInterface $logger,
		private ISecureRandom $secureRandom,
		private UserNodeResolver $userNodeResolver,
		private \OCA\EtherpadNextcloud\Util\PathNormalizer $padPaths,
		private ITimeFactory $timeFactory,
			private ProvisionedPadRollback $provisionedPadRollback,
	) {
	}

	// ------------------------------------------------------------------
	// Public wrappers that take (uid, path/fileId), resolve the node
	// internally, and reshape the result so controllers don't have to.
	// Mirrors the surface that `PadLifecycleOperationService` used to
	// expose before it was folded into this service.
	// ------------------------------------------------------------------

	/**
	 * @return array{file:string,status:string,reason?:string,deleted_at?:int,snapshot_persisted?:bool,delete_pending?:bool}
	 * @throws \OCP\Files\NotFoundException
	 */
	public function trashByPath(string $uid, string $file): array {
		$path = $this->normalizeLifecyclePath($file);
		$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $path);
		$result = $this->handleTrash($node);

		if (($result['status'] ?? '') === self::RESULT_SKIPPED) {
			return [
				'file' => $path,
				'status' => self::RESULT_SKIPPED,
				'reason' => (string)($result['reason'] ?? 'unknown'),
			];
		}

		return [
			'file' => $path,
			'status' => self::RESULT_TRASHED,
			'deleted_at' => (int)($result['deleted_at'] ?? 0),
			'snapshot_persisted' => (bool)($result['snapshot_persisted'] ?? false),
			'delete_pending' => (bool)($result['delete_pending'] ?? false),
		];
	}

	/**
	 * @return array{file:string,status:string,reason?:string,old_pad_id?:string,new_pad_id?:string}
	 * @throws \OCP\Files\NotFoundException
	 */
	public function restoreByPath(string $uid, string $file): array {
		$path = $this->normalizeLifecyclePath($file);
		$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $path);
		$result = $this->handleRestore($node);

		if (($result['status'] ?? '') === self::RESULT_SKIPPED) {
			return [
				'file' => $path,
				'status' => self::RESULT_SKIPPED,
				'reason' => (string)($result['reason'] ?? 'unknown'),
			];
		}

		return [
			'file' => $path,
			'status' => self::RESULT_RESTORED,
			'old_pad_id' => (string)($result['old_pad_id'] ?? ''),
			'new_pad_id' => (string)($result['new_pad_id'] ?? ''),
		];
	}

	/**
	 * @return array{file_id:int,status:string,reason?:string,old_pad_id?:string,new_pad_id?:string}
	 * @throws \OCP\Files\NotFoundException
	 */
	public function recoverByFileId(string $uid, int $fileId): array {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		$result = $this->recoverFromSnapshot($node);

		if (($result['status'] ?? '') === self::RESULT_SKIPPED) {
			return [
				'file_id' => $fileId,
				'status' => self::RESULT_SKIPPED,
				'reason' => (string)($result['reason'] ?? 'unknown'),
			];
		}

		return [
			'file_id' => $fileId,
			'status' => self::RESULT_RESTORED,
			'old_pad_id' => (string)($result['old_pad_id'] ?? ''),
			'new_pad_id' => (string)($result['new_pad_id'] ?? ''),
		];
	}

	private function normalizeLifecyclePath(string $file): string {
		$path = $this->padPaths->normalizeViewerFilePath($file);
		if ($path === '') {
			throw new \InvalidArgumentException('Invalid file path.');
		}
		return $path;
	}

	/** @return array{status: string, reason?: string, file_id: int, pad_id?: string, deleted_at?: int, snapshot_persisted?: bool, delete_pending?: bool} */
	public function handleTrash(File $file): array {
		$fileId = (int)$file->getId();
		if (!$this->isPadFile($file)) {
			return $this->buildSkippedResult('not_pad_file', $fileId);
		}

		try {
			$binding = $this->bindingService->findByFileId($fileId);
			if ($binding !== null && (string)$binding['state'] === BindingService::STATE_RESTORE_PENDING) {
				// Whatever the setting says: nothing is deleted here, and a row
				// left waiting would keep the file from opening once it is back.
				$retrashed = $this->retrashUndecided($fileId, (string)$binding['pad_id']);
				if ($retrashed !== null) {
					return $retrashed;
				}
				// A sweep settled the row in between; trash it as what it is now.
				$binding = $this->bindingService->findByFileId($fileId);
			}
		} catch (\Throwable $e) {
			throw $this->failed('Trash', $e);
		}

		if (!$this->isDeleteOnTrashEnabled()) {
			return $this->buildSkippedResult('delete_on_trash_disabled', $fileId);
		}
		if ($binding === null) {
			if ($this->isExternalPadFile($file)) {
				return $this->buildSkippedResult('external_pad', $fileId);
			}
			return $this->buildSkippedResult('binding_not_found', $fileId);
		}
		$padId = (string)$binding['pad_id'];
		if ((string)$binding['state'] !== BindingService::STATE_ACTIVE) {
			return $this->buildSkippedResult('binding_not_active', $fileId, $padId);
		}

		$deletedAt = $this->timeFactory->getTime();

		try {
			if (!$this->persistTrashSnapshot($file, $fileId, $padId)) {
				// Without a fresh snapshot the pad may hold what the file lacks,
				// so it stays as it is and its deletion is owed: a restore takes
				// it back, and the sweep deletes it once the file is gone for good.
				if (!$this->bindingService->transition($fileId, $padId, BindingService::STATE_ACTIVE, BindingService::STATE_PENDING_DELETE)) {
					throw new BindingStateConflictException('State transition conflict while marking pending_delete (expected active).');
				}
				return $this->buildTrashedResult($fileId, $padId, $deletedAt, false, true);
			}

			try {
				$this->padLifecycle->discard($padId);
			} catch (\Throwable $deleteError) {
				if (EtherpadErrorClassifier::isPadAlreadyDeleted($deleteError)) {
					$this->logger->info('Pad already deleted while processing trash; deleting binding row.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						...SafeError::context($deleteError),
					]);
				} else {
					if (!$this->bindingService->transition($fileId, $padId, BindingService::STATE_ACTIVE, BindingService::STATE_PENDING_DELETE)) {
						throw new BindingStateConflictException('State transition conflict while marking pending_delete (expected active).');
					}
					$this->logger->warning('Could not delete the pad after trash. It is kept, and its deletion recorded as pending.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						...SafeError::context($deleteError),
					]);
					return $this->buildTrashedResult($fileId, $padId, $deletedAt, true, true);
				}
			}
			$this->bindingService->deleteByFileId($fileId);
			return $this->buildTrashedResult($fileId, $padId, $deletedAt, true, false);
		} catch (BindingStateConflictException $e) {
			$this->logger->warning('Trash lifecycle state transition conflict. Returning skipped.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
			return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $padId);
		} catch (\Throwable $e) {
			// Not reported here. Every caller catches to log, and a caller
			// also sees the ways out that end above this try - reporting
			// from in here would be the same failure a second time, from
			// the one of the two places that cannot see all of them.
			throw $this->failed('Trash', $e);
		}
	}

	/**
	 * Take the pad's current content into the file, as the snapshot a trash
	 * leaves behind. True only once it is written: a pad is not deleted on
	 * anything less.
	 *
	 * A delete through WebDAV holds the file's lock while the trash is
	 * decided, so a locked file is the ordinary case here, not a fault.
	 */
	private function persistTrashSnapshot(File $file, int $fileId, string $padId): bool {
		$context = ['app' => 'etherpad_nextcloud', 'fileId' => $fileId];
		try {
			if ($this->isTestFaultActive(self::TEST_FAULT_TRASH_READ_LOCK)) {
				throw new LockedException('Injected test fault: trash_read_lock');
			}
			$currentContent = (string)$file->getContent();
		} catch (LockedException) {
			$this->logger->debug('A trashed .pad file is locked by its delete. Its pad is kept as it is.', $context);
			return false;
		}
		if ($currentContent === '') {
			return false;
		}

		try {
			$updatedContent = $this->padFileService->withExportSnapshot(
				$this->padFileService->readPad($currentContent),
				new PadSnapshot(
					$this->etherpadClient->getText($padId),
					$this->etherpadClient->getHTML($padId),
					$this->etherpadClient->getRevisionsCount($padId),
				),
			);
		} catch (\Throwable $snapshotError) {
			$this->logger->warning('Could not fetch a fresh snapshot for a trashed .pad file. Its pad is kept as it is.', [...$context, ...SafeError::context($snapshotError)]);
			return false;
		}

		try {
			if ($this->isTestFaultActive(self::TEST_FAULT_TRASH_WRITE_LOCK)) {
				throw new LockedException('Injected test fault: trash_write_lock');
			}
			if ($this->isTestFaultActive(self::TEST_FAULT_TRASH_WRITE_FAIL)) {
				throw new \RuntimeException('Injected test fault: trash_write_fail');
			}
			$file->putContent($updatedContent);
			return true;
		} catch (LockedException) {
			$this->logger->debug('A trashed .pad file is locked by its delete. Its pad is kept as it is.', $context);
		} catch (\Throwable $writeError) {
			$this->logger->warning('Could not write the snapshot of a trashed .pad file. Its pad is kept as it is.', [...$context, ...SafeError::context($writeError)]);
		}
		return false;
	}

	/**
	 * Trashed again while its restore is undecided. The pad may hold the only
	 * current copy, so it is left alone, and no snapshot is taken: one of a
	 * pad that is not the file's would overwrite the only good copy. The row
	 * goes back to a deletion owed.
	 *
	 * Null when the row moved on first, a sweep having settled it, so the
	 * caller trashes the file as what its row says now.
	 *
	 * @return array{status: string, file_id: int, pad_id: string, deleted_at: int, snapshot_persisted: bool, delete_pending: bool}|null
	 */
	private function retrashUndecided(int $fileId, string $padId): ?array {
		$deletedAt = $this->timeFactory->getTime();
		if (!$this->bindingService->transition($fileId, $padId, BindingService::STATE_RESTORE_PENDING, BindingService::STATE_PENDING_DELETE)) {
			return null;
		}
		return $this->buildTrashedResult($fileId, $padId, $deletedAt, false, true);
	}

	/** @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string} */
	public function handleRestore(File $file): array {
		$fileId = (int)$file->getId();
		if (!$this->isPadFile($file)) {
			return $this->buildSkippedResult('not_pad_file', $fileId);
		}

		$binding = $this->findBindingForRestore($fileId);
		if ($binding === null) {
			// No row to settle. Whether to make a new pad is the setting's
			// call, as deleting the old one was.
			if (!$this->isDeleteOnTrashEnabled()) {
				return $this->buildSkippedResult('delete_on_trash_disabled', $fileId);
			}
			return $this->restoreWithoutBinding($file, $fileId);
		}
		if (!$this->isWaiting($binding)) {
			return $this->buildSkippedResult('binding_not_pending_delete', $fileId, (string)$binding['pad_id']);
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
	 * An unreadable file is left rather than counted against the run: it
	 * says nothing about whether Etherpad answers.
	 */
	public function settleWaitingFile(File $file, ?int $probeTimeoutSeconds = null): SettleOutcome {
		if (!$this->isPadFile($file)) {
			return SettleOutcome::Left;
		}
		$binding = $this->findBindingForRestore((int)$file->getId());
		if ($binding === null || !$this->isWaiting($binding)) {
			return SettleOutcome::Left;
		}
		$result = $this->settleWaitingBinding($file, $binding, mayReplace: false, probeTimeoutSeconds: $probeTimeoutSeconds);
		$reason = $result['reason'] ?? '';
		return match (true) {
			($result['status'] ?? '') === self::RESULT_RESTORED, $reason === self::REASON_RELEASED => SettleOutcome::Settled,
			$reason === self::REASON_PRESENCE_UNKNOWN => SettleOutcome::Unanswered,
			default => SettleOutcome::Left,
		};
	}

	/** @param array<string,mixed> $binding */
	private function isWaiting(array $binding): bool {
		return in_array((string)$binding['state'], [BindingService::STATE_PENDING_DELETE, BindingService::STATE_RESTORE_PENDING], true);
	}

	/** @return array<string,mixed>|null */
	private function findBindingForRestore(int $fileId): ?array {
		try {
			return $this->bindingService->findByFileId($fileId);
		} catch (\Throwable $e) {
			throw $this->failed('Restore', $e);
		}
	}

	/**
	 * The row names the pad the file had before the trash, and that pad may
	 * hold its only current copy: the deletion was owed, not done, and the
	 * trash's snapshot may be older than the pad. The pad id comes from the
	 * row, never from the file: a pad id in a file is anyone's to write.
	 *
	 * The file is read first for its snapshot revision: a pad under that id
	 * with fewer revisions is not the pad the file knew.
	 *
	 * @param array<string,mixed> $binding
	 * @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	private function settleWaitingBinding(File $file, array $binding, bool $mayReplace, ?int $probeTimeoutSeconds = null): array {
		$fileId = (int)$file->getId();
		$padId = (string)$binding['pad_id'];
		$state = (string)$binding['state'];
		try {
			try {
				$pad = $this->readRestoredPad($file);
			} catch (\Throwable $readError) {
				// No revision to hold the pad to, so no decision either.
				return $this->deferRestore($fileId, $padId, $state, $readError);
			}
			$presence = $this->padLifecycle->presenceOf($padId, $pad->snapshotRev, ['fileId' => $fileId], $probeTimeoutSeconds);
			if ($presence === PadPresence::Behind) {
				// Logged before anything is tried, since whatever follows may
				// fail: someone may have written into it since it came back,
				// and this is the last record of where it is.
				$this->logger->warning('A pad has fewer revisions than its file\'s snapshot and is no longer the file\'s. It is left in place.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'padId' => $padId,
				]);
			}
			return match ($presence) {
				PadPresence::Present => $this->resumeOwnPad($fileId, $padId, $state),
				PadPresence::Unknown => $this->deferRestore($fileId, $padId, $state),
				PadPresence::Absent, PadPresence::Behind => $mayReplace
					? $this->restoreWithReplacement($file, $pad, $fileId, $padId, $state, (string)$binding['access_mode'], $presence)
					: $this->releaseWaitingRow($fileId, $padId, $state),
			};
		} catch (LifecycleException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->failed('Restore', $e);
		}
	}

	/** @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string} */
	private function resumeOwnPad(int $fileId, string $padId, string $fromState): array {
		if (!$this->bindingService->transition($fileId, $padId, $fromState, BindingService::STATE_ACTIVE)) {
			return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $padId);
		}
		return $this->buildRestoredResult($fileId, $padId, $padId);
	}

	/**
	 * Etherpad could not be asked, or the file could not be read, so which
	 * pad is the file's is not known. Reactivating could bind the file to a
	 * pad that is gone or another one; replacing could give up the only
	 * current copy. The row waits for an answer.
	 *
	 * @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	private function deferRestore(int $fileId, string $padId, string $fromState, ?\Throwable $readError = null): array {
		if ($fromState === BindingService::STATE_RESTORE_PENDING) {
			// The same state again moves only updated_at: a row that keeps
			// waiting goes to the back, behind the rows that may not.
			$this->bindingService->transition($fileId, $padId, $fromState, $fromState);
		} else {
			if (!$this->bindingService->transition($fileId, $padId, $fromState, BindingService::STATE_RESTORE_PENDING)) {
				return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $padId);
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
		return $this->buildSkippedResult($readError === null ? self::REASON_PRESENCE_UNKNOWN : self::REASON_FILE_UNREADABLE, $fileId, $padId);
	}

	/**
	 * The row's pad is not the file's any more: Etherpad has no such pad, or
	 * one with fewer revisions than the file's snapshot. The snapshot is all
	 * that is left of the file's pad, and a new pad is made from it.
	 *
	 * The row is claimed before the file is touched, as restoreWithoutBinding
	 * does it: whoever loses the claim has written nothing, and so has
	 * nothing to put back over someone else's content. After the claim the
	 * only step left is the write, so there is no file to roll back either.
	 *
	 * @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	private function restoreWithReplacement(File $file, ParsedPadFile $pad, int $fileId, string $oldPadId, string $fromState, string $accessMode, PadPresence $presence): array {
		if (PadAccessMode::tryFrom($accessMode) === null) {
			// No pad can be made on this row. It goes, and the file offers
			// its own recovery, which goes by the file's access mode.
			$this->releaseReplacedRow($fileId, $oldPadId, $fromState);
			return $this->buildSkippedResult('unknown_access_mode', $fileId, $oldPadId);
		}

		try {
			[$newPadId, $updatedContent] = $this->seedFromSnapshot($fileId, $pad, $accessMode, $oldPadId);
		} catch (\Throwable $e) {
			$this->releaseReplacedRow($fileId, $oldPadId, $fromState);
			throw $this->failed('Restore', $e);
		}

		try {
			if (!$this->claimForReplacement($fileId, $oldPadId, $fromState, $newPadId)) {
				// Another flow holds the row, and the file is its to write.
				$this->provisionedPadRollback->discardUnlessBoundToFile($fileId, $newPadId, 'restore with replacement');
				return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $oldPadId);
			}
			$this->writeRestoredContent($file, $updatedContent);
		} catch (\Throwable $e) {
			// The claim may have landed, landed without saying so, or since
			// been taken over by a trash. An active row naming the replacement
			// goes with it; one a trash took over keeps it. The row still
			// naming the old pad goes too: that pad is not the file's, and
			// without a row the file offers its own recovery.
			$this->provisionedPadRollback->removeMatchingBindingAndDiscard($fileId, $newPadId, 'restore with replacement');
			$this->releaseReplacedRow($fileId, $oldPadId, $fromState);
			throw $this->failed('Restore', $e);
		}

		// Outside the try on purpose: the restore is done and recorded, and
		// nothing about clearing up after it may turn that into a failure.
		if ($presence === PadPresence::Absent) {
			$this->discardSupersededPad($fileId, $oldPadId);
		}
		return $this->buildRestoredResult($fileId, $oldPadId, $newPadId);
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
	 * A sweep's answer to a pad that is not the file's any more: the row
	 * goes, and the file makes its own pad from its snapshot when someone
	 * opens it.
	 *
	 * @return array{status: string, reason: string, file_id: int, pad_id?: string}
	 */
	private function releaseWaitingRow(int $fileId, string $padId, string $state): array {
		if (!$this->releaseReplacedRow($fileId, $padId, $state)) {
			return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $padId);
		}
		// The answer to an admin asking why a file suddenly wants recovering.
		$this->logger->info('Released the binding of a file whose pad is no longer its own. The file offers its own recovery.', [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'padId' => $padId,
		]);
		return $this->buildSkippedResult(self::REASON_RELEASED, $fileId, $padId);
	}

	/**
	 * The pad a replacement stood in for. Etherpad has already said it does
	 * not exist, but for a protected pad its group can still be standing
	 * with nothing in it, and discard() is what takes an empty group down.
	 * Best effort: the restore is done, and a group left over is garbage,
	 * not a way in - there is no pad in it for a session to open.
	 */
	private function discardSupersededPad(int $fileId, string $oldPadId): void {
		try {
			$this->padLifecycle->discard($oldPadId);
		} catch (\Throwable $e) {
			if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				return;
			}
			// The row names the replacement now, so nothing else leads here.
			$this->logger->warning('Could not remove what was left of the pad a restore replaced.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $oldPadId,
				...SafeError::context($e),
			]);
		}
	}

	/** The `.pad` back from the trash, as a restore from its snapshot reads it. */
	private function readRestoredPad(File $file): ParsedPadFile {
		if ($this->isTestFaultActive(self::TEST_FAULT_RESTORE_READ_LOCK)) {
			throw new LockedException('Injected test fault: restore_read_lock');
		}
		return $this->padFileService->readPad((string)$file->getContent());
	}

	/**
	 * A new pad holding the file's snapshot, and the `.pad` content naming
	 * it: what both restores from a snapshot share. Which row the pad gets,
	 * and how that is undone, stays with each caller. A failure here removes
	 * the pad here; no row names it yet.
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
	 * Manual recovery entry point for `.pad` files that ended up without a
	 * binding row (backup restore via WebDAV, `occ files:scan`, manual DB
	 * intervention, or a file copy that never received a restore event).
	 *
	 * Takes the path a restore takes for a file without a row
	 * (restoreWithoutBinding), guarded so it never replaces an existing
	 * binding: the caller has already verified the user owns the file, and
	 * the `pad_id` in the frontmatter is never reused.
	 *
	 * @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	public function recoverFromSnapshot(File $file): array {
		$fileId = (int)$file->getId();
		if (!$this->isPadFile($file)) {
			throw new NotAPadFileException('File is not a .pad file.');
		}
		$binding = $this->bindingService->findByFileId($fileId);
		if ($binding !== null) {
			throw new PadAlreadyHasBindingException('A binding already exists for this file.');
		}
		$result = $this->restoreWithoutBinding($file, $fileId);
		if (($result['status'] ?? '') === self::RESULT_RESTORED) {
			$this->logger->info('Pad recovered from snapshot.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
			]);
		}
		return $result;
	}

	/** @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string} */
	private function restoreWithoutBinding(File $file, int $fileId): array {
		try {
			$pad = $this->readRestoredPad($file);
			if (str_starts_with($pad->padId, 'ext.') || $pad->isExternal) {
				return $this->buildSkippedResult('external_pad', $fileId, $pad->padId);
			}
			[$newPadId, $updatedContent] = $this->seedFromSnapshot($fileId, $pad, $pad->accessMode, $pad->padId);
		} catch (\Throwable $e) {
			throw $this->failed('Restore', $e);
		}

		try {
			// Claim the binding row before touching the file. The unique
			// constraint on file_id is our serialization point against a
			// concurrent recovery for the same file — if another request
			// got here first, createBinding throws and we abort cleanly
			// without overwriting their .pad content.
			$this->bindingService->createBinding($fileId, $newPadId, $pad->accessMode);
			$this->writeRestoredContent($file, $updatedContent);
		} catch (\Throwable $e) {
			// Nothing consistent to keep, unlike a first init: a row naming
			// the new pad would contradict a `.pad` that still names the old.
			$this->provisionedPadRollback->removeMatchingBindingAndDiscard($fileId, $newPadId, 'restore without binding');
			throw $this->failed('Restore', $e);
		}

		return $this->buildRestoredResult($fileId, $pad->padId, $newPadId);
	}

	/** @return array{status: string, file_id: int, old_pad_id: string, new_pad_id: string} */
	private function buildRestoredResult(int $fileId, string $oldPadId, string $newPadId): array {
		return [
			'status' => self::RESULT_RESTORED,
			'file_id' => $fileId,
			'old_pad_id' => $oldPadId,
			'new_pad_id' => $newPadId,
		];
	}

	/** @return array{status: string, file_id: int, pad_id: string, deleted_at: int, snapshot_persisted: bool, delete_pending: bool} */
	private function buildTrashedResult(int $fileId, string $padId, int $deletedAt, bool $snapshotPersisted, bool $deletePending): array {
		return [
			'status' => self::RESULT_TRASHED,
			'file_id' => $fileId,
			'pad_id' => $padId,
			'deleted_at' => $deletedAt,
			'snapshot_persisted' => $snapshotPersisted,
			'delete_pending' => $deletePending,
		];
	}

	/** What a trash or restore that did not finish throws. Every caller reports it, so it carries its cause. */
	private function failed(string $flow, \Throwable $cause): LifecycleException {
		return new LifecycleException($flow . ' flow failed before completion.', 0, $cause);
	}

	/** @return array{status: string, reason: string, file_id: int, pad_id?: string} */
	private function buildSkippedResult(string $reason, int $fileId, ?string $padId = null): array {
		$result = [
			'status' => self::RESULT_SKIPPED,
			'reason' => $reason,
			'file_id' => $fileId,
		];
		if ($padId !== null && $padId !== '') {
			$result['pad_id'] = $padId;
		}
		$this->logger->debug('Lifecycle step skipped.', [
			'app' => 'etherpad_nextcloud',
			'reason' => $reason,
			'fileId' => $fileId,
		]);
		return $result;
	}

	private function isDeleteOnTrashEnabled(): bool {
		return (string)$this->config->getAppValue('etherpad_nextcloud', 'delete_on_trash', 'yes') === 'yes';
	}

	private function isPadFile(File $file): bool {
		return PadFileType::isPad($file->getName());
	}

	/** Callers that already have `getContent()` can pass it to skip a re-read. */
	private function isExternalPadFile(File $file, ?string $content = null): bool {
		try {
			if ($content === null) {
				$content = (string)$file->getContent();
			}
			$pad = $this->padFileService->readPad($content);
			return str_starts_with($pad->padId, 'ext.') || $pad->isExternal;
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

	private function writeRestoredContent(File $file, string $updatedContent): void {
		if ($this->isTestFaultActive(self::TEST_FAULT_RESTORE_WRITE_LOCK)) {
			throw new LockedException('Injected test fault: restore_write_lock');
		}
		if ($this->isTestFaultActive(self::TEST_FAULT_RESTORE_WRITE_FAIL)) {
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

	/** @return list<string> */
	public static function getSupportedTestFaults(): array {
		return [
			self::TEST_FAULT_TRASH_READ_LOCK,
			self::TEST_FAULT_TRASH_WRITE_LOCK,
			self::TEST_FAULT_TRASH_WRITE_FAIL,
			self::TEST_FAULT_RESTORE_READ_LOCK,
			self::TEST_FAULT_RESTORE_WRITE_LOCK,
			self::TEST_FAULT_RESTORE_WRITE_FAIL,
		];
	}

	private function isTestFaultActive(string $fault): bool {
		if (!$this->config->getSystemValueBool('debug', false)) {
			return false;
		}
		$active = trim((string)$this->config->getAppValue('etherpad_nextcloud', 'test_fault', ''));
		return $active !== '' && hash_equals($active, $fault);
	}

}
