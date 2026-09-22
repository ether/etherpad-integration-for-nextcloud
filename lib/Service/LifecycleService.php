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

		if (!$this->isDeleteOnTrashEnabled()) {
			return $this->buildSkippedResult('delete_on_trash_disabled', $fileId);
		}

		$binding = $this->bindingService->findByFileId($fileId);
		if ($binding === null) {
			if ($this->isExternalPadFile($file)) {
				return $this->buildSkippedResult('external_pad', $fileId);
			}
			return $this->buildSkippedResult('binding_not_found', $fileId);
		}
		$padId = (string)$binding['pad_id'];
		if ((string)$binding['state'] === BindingService::STATE_RESTORE_PENDING) {
			return $this->retrashUndecided($fileId, $padId);
		}
		if ((string)$binding['state'] !== BindingService::STATE_ACTIVE) {
			return $this->buildSkippedResult('binding_not_active', $fileId, $padId);
		}

		$deletedAt = $this->timeFactory->getTime();
		$currentContent = '';
		$snapshotPersisted = false;
		$canPersistSnapshotToFile = true;

		try {
			try {
				if ($this->isTestFaultActive(self::TEST_FAULT_TRASH_READ_LOCK)) {
					throw new LockedException('Injected test fault: trash_read_lock');
				}
				$currentContent = (string)$file->getContent();
			} catch (LockedException $readLockError) {
				$canPersistSnapshotToFile = false;
				$this->logger->warning('Could not read .pad content during trash because file is locked. Continuing without snapshot persistence.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					...SafeError::context($readLockError),
				]);
			}

			if ($canPersistSnapshotToFile && $currentContent !== '') {
				$updatedContent = null;
				try {
					$snapshot = $this->etherpadClient->getText($padId);
					$html = $this->etherpadClient->getHTML($padId);
					$revision = $this->etherpadClient->getRevisionsCount($padId);
					$updatedContent = $this->padFileService->withExportSnapshot(
						$this->padFileService->readPad($currentContent),
						new PadSnapshot($snapshot, $html, $revision),
					);
				} catch (\Throwable $snapshotError) {
					$this->logger->warning('Could not fetch fresh Etherpad snapshot during trash. Using current .pad snapshot/body.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						...SafeError::context($snapshotError),
					]);
				}

				if ($updatedContent !== null) {
					try {
						if ($this->isTestFaultActive(self::TEST_FAULT_TRASH_WRITE_LOCK)) {
							throw new LockedException('Injected test fault: trash_write_lock');
						}
						if ($this->isTestFaultActive(self::TEST_FAULT_TRASH_WRITE_FAIL)) {
							throw new \RuntimeException('Injected test fault: trash_write_fail');
						}
						$file->putContent($updatedContent);
						$snapshotPersisted = true;
					} catch (LockedException $e) {
						// Trash operation can hold a lock on the file node; do not block state transition/deletion.
						$this->logger->warning('Could not persist trash snapshot due to file lock. Continuing with pad deletion.', [
							'app' => 'etherpad_nextcloud',
							'fileId' => $fileId,
							...SafeError::context($e),
						]);
					} catch (\Throwable $writeError) {
						$this->logger->warning('Could not persist trash snapshot to .pad file. Continuing with pad deletion.', [
							'app' => 'etherpad_nextcloud',
							'fileId' => $fileId,
							...SafeError::context($writeError),
						]);
					}
				}
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
					$this->bindingService->markPendingDelete($fileId, $deletedAt);
					$this->logger->warning('Pad delete deferred after trash. Will retry via background job.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						...SafeError::context($deleteError),
					]);
					return [
						'status' => self::RESULT_TRASHED,
						'file_id' => $fileId,
						'pad_id' => $padId,
						'deleted_at' => $deletedAt,
						'snapshot_persisted' => $snapshotPersisted,
						'delete_pending' => true,
					];
				}
			}
			$this->bindingService->deleteByFileId($fileId);
			return [
				'status' => self::RESULT_TRASHED,
				'file_id' => $fileId,
				'pad_id' => $padId,
				'deleted_at' => $deletedAt,
				'snapshot_persisted' => $snapshotPersisted,
				'delete_pending' => false,
			];
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
			throw new LifecycleException('Trash flow failed before completion.', 0, $e);
		}
	}

	/**
	 * Trashed again before anyone could tell whether its pad is still there.
	 * The pad is left alone, since it may hold the only current copy, and the
	 * row goes back to what it was before the restore: a deletion still owed.
	 *
	 * @return array{status: string, reason?: string, file_id: int, pad_id?: string, deleted_at?: int, snapshot_persisted?: bool, delete_pending?: bool}
	 */
	private function retrashUndecided(int $fileId, string $padId): array {
		$deletedAt = $this->timeFactory->getTime();
		if (!$this->bindingService->transition($fileId, $padId, BindingService::STATE_RESTORE_PENDING, BindingService::STATE_PENDING_DELETE)) {
			return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $padId);
		}
		return [
			'status' => self::RESULT_TRASHED,
			'file_id' => $fileId,
			'pad_id' => $padId,
			'deleted_at' => $deletedAt,
			'snapshot_persisted' => false,
			'delete_pending' => true,
		];
	}

	/** @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string} */
	public function handleRestore(File $file): array {
		$fileId = (int)$file->getId();
		if (!$this->isPadFile($file)) {
			return $this->buildSkippedResult('not_pad_file', $fileId);
		}

		if (!$this->isDeleteOnTrashEnabled()) {
			return $this->buildSkippedResult('delete_on_trash_disabled', $fileId);
		}

		$binding = $this->bindingService->findByFileId($fileId);
		if ($binding === null) {
			return $this->restoreWithoutBinding($file, $fileId);
		}
		$padId = (string)$binding['pad_id'];
		if ((string)$binding['state'] !== BindingService::STATE_PENDING_DELETE) {
			return $this->buildSkippedResult('binding_not_pending_delete', $fileId, $padId);
		}

		// The row names the pad this file had before the trash, and that pad
		// may hold its only current copy: the deletion was owed rather than
		// done, and the snapshot the trash tried to take can be older than
		// the pad. Taken from the row, never from the file - a pad id a file
		// carries is anyone's to write, the row is this app's own record.
		return match ($this->padLifecycle->presenceOf($padId)) {
			PadPresence::Present => $this->resumeOwnPad($fileId, $padId),
			PadPresence::Unknown => $this->deferRestore($fileId, $padId),
			PadPresence::Absent => $this->restoreWithReplacement($file, $fileId, $padId, (string)$binding['access_mode']),
		};
	}

	/** @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string} */
	private function resumeOwnPad(int $fileId, string $padId): array {
		if (!$this->bindingService->transition($fileId, $padId, BindingService::STATE_PENDING_DELETE, BindingService::STATE_ACTIVE)) {
			return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $padId);
		}
		return [
			'status' => self::RESULT_RESTORED,
			'file_id' => $fileId,
			'old_pad_id' => $padId,
			'new_pad_id' => $padId,
		];
	}

	/**
	 * Etherpad could not be asked, so whether the pad is there is not
	 * known - and a guess either way is wrong for someone: reactivating
	 * binds the file to a pad that may be gone, replacing discards one that
	 * may hold the only current copy. The file is restored all the same;
	 * the row waits for an answer.
	 *
	 * @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	private function deferRestore(int $fileId, string $padId): array {
		if (!$this->bindingService->transition($fileId, $padId, BindingService::STATE_PENDING_DELETE, BindingService::STATE_RESTORE_PENDING)) {
			return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $padId);
		}
		$this->logger->warning('Could not tell whether a restored pad still exists. Kept it for a later check.', [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
		]);
		return $this->buildSkippedResult('pad_presence_unknown', $fileId, $padId);
	}

	/**
	 * Etherpad has confirmed the old pad is gone, so the file's snapshot is
	 * all that is left, and a new pad is made from it.
	 *
	 * The row is claimed before the file is touched, as restoreWithoutBinding
	 * does it: whoever loses the claim has written nothing, and so has
	 * nothing to put back over someone else's content. After the claim the
	 * only step left is the write, so there is no file to roll back either.
	 *
	 * @return array{status: string, reason?: string, file_id: int, pad_id?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	private function restoreWithReplacement(File $file, int $fileId, string $oldPadId, string $accessMode): array {
		try {
			$newPadId = $this->provisionRestorePadId($accessMode, $oldPadId);
		} catch (\InvalidArgumentException) {
			// Leaves the row in pending_delete for a repair rather than
			// taking the file's own restore down with it.
			return $this->buildSkippedResult('unknown_access_mode', $fileId, $oldPadId);
		} catch (\Throwable $e) {
			$this->handOverFailedRestore($fileId, $oldPadId, null);
			throw new LifecycleException('Restore flow failed before completion.', 0, $e);
		}

		$claimed = false;
		try {
			if ($this->isTestFaultActive(self::TEST_FAULT_RESTORE_READ_LOCK)) {
				throw new LockedException('Injected test fault: restore_read_lock');
			}
			$pad = $this->padFileService->readPad((string)$file->getContent());
			$snapshotParts = $this->padFileService->getSnapshotPartsFromBody($pad->body);
			$this->padLifecycle->seed($newPadId, $snapshotParts['text'], $snapshotParts['html'], ['fileId' => $fileId]);
			$updatedContent = $this->padFileService->withRestoredSnapshot(
				$pad,
				$snapshotParts['text'],
				$snapshotParts['html'],
				$newPadId,
				$this->etherpadClient->buildPadUrl($newPadId),
			);

			$claimed = $this->claimForReplacement($fileId, $oldPadId, $newPadId);
			if (!$claimed) {
				$this->discardReplacement($fileId, $newPadId);
				return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $oldPadId);
			}
			$this->writeRestoredContent($file, $updatedContent);
		} catch (\Throwable $e) {
			$this->handOverFailedRestore($fileId, $oldPadId, $claimed ? $newPadId : null);
			$this->discardReplacement($fileId, $newPadId);
			throw new LifecycleException('Restore flow failed before completion.', 0, $e);
		}

		// Outside the try on purpose: the restore is done and recorded, and
		// nothing about clearing up after it may turn that into a failure.
		$this->discardSupersededPad($fileId, $oldPadId);
		return [
			'status' => self::RESULT_RESTORED,
			'file_id' => $fileId,
			'old_pad_id' => $oldPadId,
			'new_pad_id' => $newPadId,
		];
	}

	/**
	 * Point the row at the replacement, if it still names the old pad. An
	 * update can commit and still fail to say so, and taking that for a
	 * refusal would discard the very pad the row now names - so a throw is
	 * settled by asking the row. When even that cannot be answered, the
	 * claim is taken as made: a replacement left standing is only garbage,
	 * one discarded under a row that names it is a pad that is gone.
	 */
	private function claimForReplacement(int $fileId, string $oldPadId, string $newPadId): bool {
		try {
			return $this->bindingService->rebind($fileId, $oldPadId, BindingService::STATE_PENDING_DELETE, $newPadId, BindingService::STATE_ACTIVE);
		} catch (\Throwable $claimError) {
			try {
				return $this->bindingService->isBoundTo($fileId, $newPadId);
			} catch (\Throwable $readError) {
				$this->logger->warning('Could not tell whether a restore claimed its binding; keeping the replacement pad.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					...SafeError::context($readError),
				]);
				return true;
			}
		}
	}

	/**
	 * The file is back in Files whatever happened here, so the row must not
	 * stay in pending_delete: nothing would ever come for it there. It waits
	 * as a restore still to be settled, naming the pad it named before, and
	 * the recheck takes it from there.
	 */
	private function handOverFailedRestore(int $fileId, string $oldPadId, ?string $claimedPadId): void {
		$handedOver = $claimedPadId === null
			? $this->bindingService->transition($fileId, $oldPadId, BindingService::STATE_PENDING_DELETE, BindingService::STATE_RESTORE_PENDING)
			: $this->bindingService->rebind($fileId, $claimedPadId, BindingService::STATE_ACTIVE, $oldPadId, BindingService::STATE_RESTORE_PENDING);
		if (!$handedOver) {
			$this->logger->warning('Could not hand a failed restore over for a later check.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
			]);
		}
	}

	/**
	 * The pad a replacement stood in for. Etherpad has already said it does
	 * not exist, but for a protected pad its group can still be standing
	 * with nothing in it, and discard() is what takes an empty group down.
	 * Best effort: the restore is done, and a group left over is garbage,
	 * not a way in - there is no pad in it for a session to open.
	 */
	private function discardSupersededPad(int $fileId, string $oldPadId): void {
		if ($oldPadId === '' || str_starts_with($oldPadId, 'ext.')) {
			return;
		}

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

	/** A replacement that never became the file's pad. Nothing names it, so it only goes. */
	private function discardReplacement(int $fileId, string $newPadId): void {
		try {
			$this->padLifecycle->discardProvisioned($newPadId);
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not remove a replacement pad a restore did not use.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				// Bound to nothing, so only its name can find it again.
				'padId' => $newPadId,
				...SafeError::context($cleanupError),
			]);
		}
	}

	/**
	 * Manual recovery entry point for `.pad` files that ended up without a
	 * binding row (backup restore via WebDAV, `occ files:scan`, manual DB
	 * intervention, or a file copy that never received a restore event).
	 *
	 * Reuses the same "frontmatter → fresh pad" path as the NodeRestoredEvent
	 * flow but is guarded so it cannot replace an existing binding: the
	 * caller has already verified the user owns the file, and the security
	 * model demands we never reuse the `pad_id` from frontmatter.
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
		$newPadId = '';
		$fileContentUpdated = false;
		$managedPadCreated = false;

		try {
			if ($this->isTestFaultActive(self::TEST_FAULT_RESTORE_READ_LOCK)) {
				throw new LockedException('Injected test fault: restore_read_lock');
			}
			$pad = $this->padFileService->readPad((string)$file->getContent());
			$oldPadId = $pad->padId;
			$accessMode = $pad->accessMode;
			if (str_starts_with($oldPadId, 'ext.') || $pad->isExternal) {
				return $this->buildSkippedResult('external_pad', $fileId, $oldPadId);
			}
			$snapshotParts = $this->padFileService->getSnapshotPartsFromBody($pad->body);
			$snapshot = $snapshotParts['text'];
			$htmlSnapshot = $snapshotParts['html'];
			$newPadId = $this->provisionRestorePadId($accessMode, $oldPadId);
			$managedPadCreated = true;
			$this->padLifecycle->seed($newPadId, $snapshot, $htmlSnapshot, ['fileId' => $fileId]);
			$updatedContent = $this->padFileService->withRestoredSnapshot(
				$pad,
				$snapshot,
				$htmlSnapshot,
				$newPadId,
				$this->etherpadClient->buildPadUrl($newPadId),
			);
			// Claim the binding row before touching the file. The unique
			// constraint on file_id is our serialization point against a
			// concurrent recovery for the same file — if another request
			// got here first, createBinding throws and we abort cleanly
			// without overwriting their .pad content.
			$this->bindingService->createBinding($fileId, $newPadId, $accessMode);
			$this->writeRestoredContent($file, $updatedContent);
			$fileContentUpdated = true;

			return [
				'status' => self::RESULT_RESTORED,
				'file_id' => $fileId,
				'old_pad_id' => $oldPadId,
				'new_pad_id' => $newPadId,
			];
		} catch (\Throwable $e) {
			if ($managedPadCreated && $newPadId !== '' && !$fileContentUpdated) {
				$this->unwindUnwrittenRestore($fileId, $newPadId);
			}
			throw new LifecycleException('Restore flow failed before completion.', 0, $e);
		}
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

	/**
	 * Nothing consistent to keep, unlike a first init: the row names the
	 * new pad while the `.pad` still names the old one.
	 */
	private function unwindUnwrittenRestore(int $fileId, string $newPadId): void {
		$this->provisionedPadRollback->removeMatchingBindingAndDiscard($fileId, $newPadId, 'restore without binding');
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

	private function buildPublicRestorePadId(string $oldPadId): string {
		$suffix = $this->secureRandom->generate(12, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$normalized = preg_replace('/[^a-zA-Z0-9._$-]+/', '-', $oldPadId) ?? 'pad';
		return 'r-' . trim($normalized, '-') . '-' . $suffix;
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
