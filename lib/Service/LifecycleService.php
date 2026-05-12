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
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\ExternalPadBindingId;
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
		private IConfig $config,
		private LoggerInterface $logger,
		private ISecureRandom $secureRandom,
	) {
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
			return $this->buildSkippedResult('binding_not_found', $fileId);
		}
		if ((string)$binding['state'] !== BindingService::STATE_ACTIVE) {
			return $this->buildSkippedResult('binding_not_active', $fileId, (string)$binding['pad_id']);
		}

		$padId = (string)$binding['pad_id'];
		$deletedAt = time();
		$currentContent = '';
		$snapshotPersisted = false;
		$canPersistSnapshotToFile = true;
		$isExternal = str_starts_with($padId, 'ext.');

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
					'padId' => $padId,
					'exception' => $readLockError,
				]);
			}

			if ($canPersistSnapshotToFile && $currentContent !== '') {
				$updatedContent = null;
				try {
						$parsed = $this->padFileService->parsePadFile($currentContent);
						$frontmatter = $parsed['frontmatter'];
						$isExternal = $isExternal || $this->padFileService->isExternalFrontmatter($frontmatter, $padId);
						if ($isExternal) {
							$padUrl = isset($frontmatter['pad_url']) ? trim((string)$frontmatter['pad_url']) : '';
							if ($padUrl === '') {
							throw new \RuntimeException('External pad URL metadata is missing.');
							}
							$external = $this->etherpadClient->normalizeAndFetchExternalPublicPadText($padUrl);
							$revision = max(0, $this->padFileService->getSnapshotRevisionFromFrontmatter($frontmatter) + 1);
							$updatedContent = $this->padFileService->withExportSnapshot($currentContent, $external['text'], '', $revision, false);
						} else {
						$snapshot = $this->etherpadClient->getText($padId);
						$html = $this->etherpadClient->getHTML($padId);
						$revision = $this->etherpadClient->getRevisionsCount($padId);
						$updatedContent = $this->padFileService->withExportSnapshot($currentContent, $snapshot, $html, $revision);
					}
				} catch (\Throwable $snapshotError) {
					$this->logger->warning('Could not fetch fresh Etherpad snapshot during trash. Using current .pad snapshot/body.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'padId' => $padId,
						'exception' => $snapshotError,
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
						$file->putContent((string)$updatedContent);
						$snapshotPersisted = true;
					} catch (LockedException $e) {
						// Trash operation can hold a lock on the file node; do not block state transition/deletion.
						$this->logger->warning('Could not persist trash snapshot due to file lock. Continuing with pad deletion.', [
							'app' => 'etherpad_nextcloud',
							'fileId' => $fileId,
							'padId' => $padId,
							'exception' => $e,
						]);
					} catch (\Throwable $writeError) {
						$this->logger->warning('Could not persist trash snapshot to .pad file. Continuing with pad deletion.', [
							'app' => 'etherpad_nextcloud',
							'fileId' => $fileId,
							'padId' => $padId,
							'exception' => $writeError,
						]);
					}
				}
			}

			if ($isExternal) {
				$this->bindingService->deleteByFileId($fileId);
				return [
					'status' => self::RESULT_TRASHED,
					'file_id' => $fileId,
					'pad_id' => $padId,
					'deleted_at' => $deletedAt,
					'snapshot_persisted' => $snapshotPersisted,
					'delete_pending' => false,
				];
			}

			try {
				$this->etherpadClient->deletePad($padId);
			} catch (\Throwable $deleteError) {
				if (EtherpadErrorClassifier::isPadAlreadyDeleted($deleteError)) {
					$this->logger->info('Pad already deleted while processing trash; deleting binding row.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'padId' => $padId,
						'exception' => $deleteError,
					]);
				} else {
					$this->bindingService->markPendingDelete($fileId, $deletedAt);
					$this->logger->warning('Pad delete deferred after trash. Will retry via background job.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'padId' => $padId,
						'exception' => $deleteError,
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
				'padId' => $padId,
				'exception' => $e,
			]);
			return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $padId);
		} catch (\Throwable $e) {
			$this->logger->error('Trash lifecycle failed. Snapshot/delete aborted.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				'exception' => $e,
			]);
			throw new LifecycleException('Trash flow failed before completion.', 0, $e);
		}
	}

	/** @return array{status: string, reason?: string, file_id: int, old_pad_id?: string, new_pad_id?: string} */
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
		$bindingState = (string)$binding['state'];
		if ($bindingState !== BindingService::STATE_PENDING_DELETE) {
			return $this->buildSkippedResult('binding_not_pending_delete', $fileId, (string)$binding['pad_id']);
		}

		$oldPadId = (string)$binding['pad_id'];
		$accessMode = (string)$binding['access_mode'];
		$newPadId = $this->provisionRestorePadId($accessMode, $oldPadId);
		$restored = false;
		$fileContentUpdated = false;
		$currentContent = '';

		try {
			if ($this->isTestFaultActive(self::TEST_FAULT_RESTORE_READ_LOCK)) {
				throw new LockedException('Injected test fault: restore_read_lock');
			}
			$currentContent = (string)$file->getContent();
			$snapshot = $this->padFileService->getTextSnapshotForRestore($currentContent);
			$htmlSnapshot = $this->padFileService->getHtmlSnapshotForRestore($currentContent);

			if (trim($htmlSnapshot) !== '') {
				try {
					$this->etherpadClient->setHTML($newPadId, $htmlSnapshot);
				} catch (\Throwable $htmlRestoreError) {
					$this->logger->warning('HTML restore failed, falling back to plain text snapshot.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'oldPadId' => $oldPadId,
						'newPadId' => $newPadId,
						'exception' => $htmlRestoreError,
					]);
					$this->etherpadClient->setText($newPadId, $snapshot);
				}
			} else {
				$this->etherpadClient->setText($newPadId, $snapshot);
			}

			$updatedContent = $this->padFileService->withStateAndSnapshot(
				$currentContent,
				BindingService::STATE_ACTIVE,
				$snapshot,
				$newPadId,
				null,
				$this->etherpadClient->buildPadUrl($newPadId),
			);
			if ($this->isTestFaultActive(self::TEST_FAULT_RESTORE_WRITE_LOCK)) {
				throw new LockedException('Injected test fault: restore_write_lock');
			}
			if ($this->isTestFaultActive(self::TEST_FAULT_RESTORE_WRITE_FAIL)) {
				throw new \RuntimeException('Injected test fault: restore_write_fail');
			}
			$file->putContent($updatedContent);
			$fileContentUpdated = true;
			$this->bindingService->markRestored($fileId, $newPadId);
			$restored = true;
			return [
				'status' => self::RESULT_RESTORED,
				'file_id' => $fileId,
				'old_pad_id' => $oldPadId,
				'new_pad_id' => $newPadId,
			];
		} catch (\Throwable $e) {
			if (!$restored) {
				if ($fileContentUpdated) {
					try {
						$file->putContent($currentContent);
					} catch (\Throwable $fileRollbackError) {
						$this->logger->warning('Could not rollback .pad content after failed restore.', [
							'app' => 'etherpad_nextcloud',
							'fileId' => $fileId,
							'oldPadId' => $oldPadId,
							'newPadId' => $newPadId,
							'exception' => $fileRollbackError,
						]);
					}
				}
				try {
					$this->etherpadClient->deletePad($newPadId);
				} catch (\Throwable $cleanupError) {
					$this->logger->warning('Could not cleanup newly provisioned restore pad after failure.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'newPadId' => $newPadId,
						'exception' => $cleanupError,
					]);
				}
			}
			if ($e instanceof BindingStateConflictException) {
				$this->logger->warning('Restore lifecycle state transition conflict. Returning skipped.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'oldPadId' => $oldPadId,
					'newPadId' => $newPadId,
					'exception' => $e,
				]);
				return $this->buildSkippedResult('binding_state_transition_conflict', $fileId, $oldPadId);
			}
			$this->logger->error('Restore lifecycle failed. Pad was not fully restored.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'newPadId' => $newPadId,
				'exception' => $e,
			]);
			throw new LifecycleException('Restore flow failed before completion.', 0, $e);
		}
	}

	/** @return array{status: string, reason?: string, file_id: int, old_pad_id?: string, new_pad_id?: string} */
	private function restoreWithoutBinding(File $file, int $fileId): array {
		$oldPadId = '';
		$newPadId = '';
		$currentContent = '';
		$fileContentUpdated = false;
		$managedPadCreated = false;

		try {
			if ($this->isTestFaultActive(self::TEST_FAULT_RESTORE_READ_LOCK)) {
				throw new LockedException('Injected test fault: restore_read_lock');
			}
			$currentContent = (string)$file->getContent();
			$parsed = $this->padFileService->parsePadFile($currentContent);
				$frontmatter = $parsed['frontmatter'];
				$meta = $this->padFileService->extractPadMetadata($frontmatter);
				$oldPadId = $meta['pad_id'];
				$accessMode = $meta['access_mode'];
				$snapshotParts = $this->padFileService->getSnapshotPartsFromBody((string)$parsed['body']);
				$snapshot = $snapshotParts['text'];
				$htmlSnapshot = $snapshotParts['html'];
				$isExternal = str_starts_with($oldPadId, 'ext.') || $this->padFileService->isExternalFrontmatter($frontmatter, $oldPadId);

				if ($isExternal) {
					$padOrigin = trim((string)($frontmatter['pad_origin'] ?? ''));
					$remotePadId = trim((string)($frontmatter['remote_pad_id'] ?? ''));
					$padUrl = $meta['pad_url'];
					if ($accessMode !== BindingService::ACCESS_PUBLIC || $padOrigin === '' || $remotePadId === '' || $padUrl === '') {
						return $this->buildSkippedResult('invalid_external_frontmatter', $fileId, $oldPadId);
					}
					try {
						$external = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
					} catch (EtherpadClientException) {
						return $this->buildSkippedResult('invalid_external_frontmatter', $fileId, $oldPadId);
					}
					if ($external['origin'] !== $padOrigin || $external['pad_id'] !== $remotePadId) {
						return $this->buildSkippedResult('invalid_external_frontmatter', $fileId, $oldPadId);
					}

					$newPadId = ExternalPadBindingId::build($external['origin'], $external['pad_id'], $fileId);
					$updatedContent = $this->padFileService->withStateAndSnapshot(
						$currentContent,
						BindingService::STATE_ACTIVE,
						$snapshot,
						$newPadId,
						null,
						$external['pad_url'],
					);
				$this->writeRestoredContent($file, $updatedContent);
				$fileContentUpdated = true;
				$this->bindingService->createBinding($fileId, $newPadId, BindingService::ACCESS_PUBLIC);

				return [
					'status' => self::RESULT_RESTORED,
					'file_id' => $fileId,
					'old_pad_id' => $oldPadId,
					'new_pad_id' => $newPadId,
				];
			}

			$newPadId = $this->provisionRestorePadId($accessMode, $oldPadId);
			$managedPadCreated = true;
			$this->restoreSnapshotToManagedPad($fileId, $oldPadId, $newPadId, $snapshot, $htmlSnapshot);
			$updatedContent = $this->padFileService->withStateAndSnapshot(
				$currentContent,
				BindingService::STATE_ACTIVE,
				$snapshot,
				$newPadId,
				null,
				$this->etherpadClient->buildPadUrl($newPadId),
			);
			$this->writeRestoredContent($file, $updatedContent);
			$fileContentUpdated = true;
			$this->bindingService->createBinding($fileId, $newPadId, $accessMode);

			return [
				'status' => self::RESULT_RESTORED,
				'file_id' => $fileId,
				'old_pad_id' => $oldPadId,
				'new_pad_id' => $newPadId,
			];
		} catch (\Throwable $e) {
			if ($fileContentUpdated) {
				try {
					$file->putContent($currentContent);
				} catch (\Throwable $fileRollbackError) {
					$this->logger->warning('Could not rollback .pad content after failed restore without binding.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'oldPadId' => $oldPadId,
						'newPadId' => $newPadId,
						'exception' => $fileRollbackError,
					]);
				}
			}
			if ($managedPadCreated && $newPadId !== '') {
				try {
					$this->etherpadClient->deletePad($newPadId);
				} catch (\Throwable $cleanupError) {
					$this->logger->warning('Could not cleanup newly provisioned restore pad after failed no-binding restore.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'newPadId' => $newPadId,
						'exception' => $cleanupError,
					]);
				}
			}
			$this->logger->error('Restore lifecycle failed without existing binding.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'oldPadId' => $oldPadId,
				'newPadId' => $newPadId,
				'exception' => $e,
			]);
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
			'padId' => $padId,
		]);
		return $result;
	}

	private function isDeleteOnTrashEnabled(): bool {
		return (string)$this->config->getAppValue('etherpad_nextcloud', 'delete_on_trash', 'yes') === 'yes';
	}

	private function isPadFile(File $file): bool {
		return str_ends_with(strtolower($file->getName()), '.pad');
	}

	private function provisionRestorePadId(string $accessMode, string $oldPadId): string {
		if ($accessMode === BindingService::ACCESS_PROTECTED) {
			$groupId = $this->etherpadClient->createGroup();
			$padName = $this->buildProtectedRestorePadName($oldPadId);
			return $this->etherpadClient->createGroupPad($groupId, $padName);
		}

		$newPadId = $this->buildPublicRestorePadId($oldPadId);
		$this->etherpadClient->createPad($newPadId);
		return $newPadId;
	}

	private function restoreSnapshotToManagedPad(int $fileId, string $oldPadId, string $newPadId, string $snapshot, string $htmlSnapshot): void {
		if (trim($htmlSnapshot) !== '') {
			try {
				$this->etherpadClient->setHTML($newPadId, $htmlSnapshot);
				return;
			} catch (\Throwable $htmlRestoreError) {
				$this->logger->warning('HTML restore failed, falling back to plain text snapshot.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'oldPadId' => $oldPadId,
					'newPadId' => $newPadId,
					'exception' => $htmlRestoreError,
				]);
			}
		}

		$this->etherpadClient->setText($newPadId, $snapshot);
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

	private function buildProtectedRestorePadName(string $oldPadId): string {
		$suffix = $this->secureRandom->generate(14, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		return 'restored-' . $suffix;
	}

	/** @return array<int,string> */
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
