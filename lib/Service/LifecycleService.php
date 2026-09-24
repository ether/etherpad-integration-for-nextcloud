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
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * A .pad file's trash and restore as they arrive: from the listeners
 * (MoveToTrashListener, RestoreFromTrashListener) and from the API, which
 * names the file by path or id. The trash is carried out here; the restore
 * is RestoreService's.
 */
class LifecycleService {
	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private ManagedPadLifecycle $padLifecycle,
		private AppConfigService $appConfig,
		private LoggerInterface $logger,
		private UserNodeResolver $userNodeResolver,
		private \OCA\EtherpadNextcloud\Util\PathNormalizer $padPaths,
		private ITimeFactory $timeFactory,
		private TrashSnapshotWriters $snapshotWriters,
		private RestoreService $restoreService,
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

		if (($result['status'] ?? '') === LifecycleResult::SKIPPED) {
			return [
				'file' => $path,
				'status' => LifecycleResult::SKIPPED,
				'reason' => (string)($result['reason'] ?? 'unknown'),
			];
		}

		return [
			'file' => $path,
			'status' => LifecycleResult::TRASHED,
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

		if (($result['status'] ?? '') === LifecycleResult::SKIPPED) {
			return [
				'file' => $path,
				'status' => LifecycleResult::SKIPPED,
				'reason' => (string)($result['reason'] ?? 'unknown'),
			];
		}

		return [
			'file' => $path,
			'status' => LifecycleResult::RESTORED,
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
		$result = $this->restoreService->recoverFromSnapshot($node);

		if (($result['status'] ?? '') === LifecycleResult::SKIPPED) {
			return [
				'file_id' => $fileId,
				'status' => LifecycleResult::SKIPPED,
				'reason' => (string)($result['reason'] ?? 'unknown'),
			];
		}

		return [
			'file_id' => $fileId,
			'status' => LifecycleResult::RESTORED,
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

	/** @return array{status: string, reason?: string, deleted_at?: int, snapshot_persisted?: bool, delete_pending?: bool} */
	public function handleTrash(File $file): array {
		$fileId = (int)$file->getId();
		if (!PadFileType::isPad($file->getName())) {
			return LifecycleResult::skipped('not_pad_file', $fileId, $this->logger);
		}

		try {
			$binding = $this->bindingService->findByFileId($fileId);
			if ($binding !== null && $binding->state === BindingService::STATE_RESTORE_PENDING) {
				// Whatever the setting says: nothing is deleted here, and a row
				// left waiting would keep the file from opening once it is back.
				$retrashed = $this->retrashUndecided($fileId, $binding->padId);
				if ($retrashed !== null) {
					return $retrashed;
				}
				// A sweep settled the row in between; trash it as what it is now.
				$binding = $this->bindingService->findByFileId($fileId);
			}
		} catch (\Throwable $e) {
			throw LifecycleException::failed('Trash', $e);
		}

		if (!$this->appConfig->isDeleteOnTrashEnabled()) {
			return LifecycleResult::skipped('delete_on_trash_disabled', $fileId, $this->logger);
		}
		if ($binding === null) {
			if ($this->isExternalPadFile($file)) {
				return LifecycleResult::skipped('external_pad', $fileId, $this->logger);
			}
			return LifecycleResult::skipped('binding_not_found', $fileId, $this->logger);
		}
		$padId = $binding->padId;
		if ($binding->state !== BindingService::STATE_ACTIVE) {
			return LifecycleResult::skipped('binding_not_active', $fileId, $this->logger);
		}

		$deletedAt = $this->timeFactory->getTime();

		try {
			$snapshots = $this->snapshotWriters->for($file, $padId);
			$pad = $snapshots->read();
			if ($pad instanceof TrashSnapshotMiss || !$snapshots->writeAtTrash($pad)) {
				// Without a fresh snapshot the pad may hold what the file lacks,
				// so it stays as it is and its deletion is owed. The sweep takes
				// the snapshot once the file sits in the trash and deletes the
				// pad then; a restore before that takes it back.
				$this->oweDeletion($fileId, $padId);
				return LifecycleResult::trashed($deletedAt, false, true);
			}

			try {
				$wasThere = $this->padLifecycle->discardIfPresent($padId);
			} catch (\Throwable $deleteError) {
				$this->oweDeletion($fileId, $padId);
				$this->logger->warning('Could not delete the pad after trash. It is kept, and its deletion recorded as pending.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					...SafeError::context($deleteError),
				]);
				return LifecycleResult::trashed($deletedAt, true, true);
			}
			if (!$wasThere) {
				$this->logger->info('Pad already deleted while processing trash; deleting binding row.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
				]);
			}
			$this->bindingService->deleteByFileId($fileId);
			return LifecycleResult::trashed($deletedAt, true, false);
		} catch (BindingStateConflictException $e) {
			$this->logger->warning('Trash lifecycle state transition conflict. Returning skipped.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
			return LifecycleResult::skipped('binding_state_transition_conflict', $fileId, $this->logger);
		} catch (\Throwable $e) {
			// Not reported here. Every caller catches to log, and a caller
			// also sees the ways out that end above this try - reporting
			// from in here would be the same failure a second time, from
			// the one of the two places that cannot see all of them.
			throw LifecycleException::failed('Trash', $e);
		}
	}

	/** The trash leaves the row a deletion owed, from an active one. */
	private function oweDeletion(int $fileId, string $padId): void {
		if (!$this->bindingService->transition($fileId, $padId, BindingService::STATE_ACTIVE, BindingService::STATE_PENDING_DELETE)) {
			throw new BindingStateConflictException('State transition conflict while marking pending_delete (expected active).');
		}
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
	 * @return array{status: string, deleted_at: int, snapshot_persisted: bool, delete_pending: bool}|null
	 */
	private function retrashUndecided(int $fileId, string $padId): ?array {
		$deletedAt = $this->timeFactory->getTime();
		if (!$this->bindingService->transition($fileId, $padId, BindingService::STATE_RESTORE_PENDING, BindingService::STATE_PENDING_DELETE)) {
			return null;
		}
		return LifecycleResult::trashed($deletedAt, false, true);
	}

	/**
	 * A restore, from the listener or the API: RestoreService's to carry
	 * out. It arrives here with the trash.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	public function handleRestore(File $file): array {
		return $this->restoreService->restore($file);
	}

	/** Callers that already have `getContent()` can pass it to skip a re-read. */
	private function isExternalPadFile(File $file, ?string $content = null): bool {
		try {
			if ($content === null) {
				$content = (string)$file->getContent();
			}
			$pad = $this->padFileService->readPad($content);
			return $pad->namesAnExternalPad();
		} catch (\Throwable) {
			return false;
		}
	}
}
