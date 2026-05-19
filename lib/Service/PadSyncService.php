<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadFileLockRetryExhaustedException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadSyncService {
	public const STATUS_UNCHANGED = 'unchanged';
	public const STATUS_UPDATED = 'updated';
	public const STATUS_LOCKED = 'locked';
	public const STATUS_SYNCED = 'synced';
	public const STATUS_OUT_OF_SYNC = 'out_of_sync';
	public const STATUS_UNAVAILABLE = 'unavailable';

	public function __construct(
		private PadFileService $padFileService,
		private UserNodeResolver $userNodeResolver,
		private PadFileLockRetryService $lockRetryService,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws NotFoundException
	 */
	public function syncById(string $uid, int $fileId, bool $force): PadSyncResult {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		if (!str_ends_with(strtolower($node->getName()), '.pad')) {
			throw new \InvalidArgumentException('Selected file is not a .pad file.');
		}

		$padId = '';
		$accessMode = '';
		$isExternal = false;
		$lockRetries = 0;

		try {
			$currentContent = (string)$node->getContent();
			$pad = $this->padFileService->readPad((string)$currentContent);
			$padId = $pad->padId;
			$accessMode = $pad->accessMode;
			$padUrl = $pad->padUrl;
			$isExternal = $pad->isExternal;
			if (!$isExternal) {
				$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);
			}

			if ($isExternal) {
				return $this->syncExternalPad($node, $fileId, $padId, $padUrl, (string)$currentContent, $force);
			}

			return $this->syncInternalPad($node, $fileId, $padId, (string)$currentContent, $force);
		} catch (PadFileLockRetryExhaustedException $e) {
			$lockRetries = $e->getRetryAttempts();
			return $this->lockedSyncResponse($e->getLockedException(), $fileId, $absolutePath, $padId, $accessMode, $isExternal, $force, $lockRetries);
		} catch (LockedException $e) {
			return $this->lockedSyncResponse($e, $fileId, $absolutePath, $padId, $accessMode, $isExternal, $force, $lockRetries);
		} catch (BindingException|PadFileFormatException|EtherpadClientException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error('Pad sync failed', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'path' => $absolutePath,
				'force' => $force,
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * @throws NotFoundException
	 */
	public function syncStatusById(string $uid, int $fileId): PadSyncStatus {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);

		try {
			$content = (string)$node->getContent();
			$pad = $this->padFileService->readPad((string)$content);
			$padId = $pad->padId;
			$accessMode = $pad->accessMode;
			$isExternal = $pad->isExternal;
			if (!$isExternal) {
				$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);
			}
			if ($isExternal) {
				return new PadSyncStatus(
					status: self::STATUS_UNAVAILABLE,
					inSync: null,
					reason: 'external_no_revision',
				);
			}

			$currentRev = $this->etherpadClient->getRevisionsCount($padId);
			$snapshotRev = $this->padFileService->getSnapshotRevision((string)$content);
			$inSync = $snapshotRev >= $currentRev;

			return new PadSyncStatus(
				status: $inSync ? self::STATUS_SYNCED : self::STATUS_OUT_OF_SYNC,
				inSync: $inSync,
				snapshotRev: $snapshotRev,
				currentRev: $currentRev,
			);
		} catch (BindingException|PadFileFormatException|EtherpadClientException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error('Pad sync status check failed', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'exception' => $e,
			]);
			throw $e;
		}
	}

	private function syncExternalPad(
		File $node,
		int $fileId,
		string $padId,
		string $padUrl,
		string $currentContent,
		bool $force,
	): PadSyncResult {
		if ($padUrl === '') {
			throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
		}
		// External sync already performs a live upstream text fetch on every call.
		// force=1 therefore only marks caller intent while preserving the no-blind-rewrite invariant.
		$external = $this->etherpadClient->normalizeAndFetchExternalPublicPadText($padUrl);
		$text = $external['text'];

		$existingText = $this->padFileService->getTextSnapshotForRestore($currentContent);
		if ($existingText === $text) {
			return new PadSyncResult(
				status: self::STATUS_UNCHANGED,
				fileId: $fileId,
				padId: $padId,
				external: true,
				forced: $force,
			);
		}

		$previousRev = $this->padFileService->getSnapshotRevision($currentContent);
		$nextRev = max(0, $previousRev + 1);
		$updatedContent = $this->padFileService->withExportSnapshot($currentContent, $text, '', $nextRev, false);
		$lockRetries = $this->lockRetryService->putContentWithSyncLockRetry($node, $updatedContent);

		return new PadSyncResult(
			status: self::STATUS_UPDATED,
			fileId: $fileId,
			padId: $padId,
			external: true,
			forced: $force,
			snapshotRev: $nextRev,
			lockRetries: $lockRetries,
		);
	}

	private function syncInternalPad(
		File $node,
		int $fileId,
		string $padId,
		string $currentContent,
		bool $force,
	): PadSyncResult {
		$currentRev = $this->etherpadClient->getRevisionsCount($padId);
		$snapshotRev = $this->padFileService->getSnapshotRevision($currentContent);
		if (!$force && $snapshotRev >= $currentRev) {
			return new PadSyncResult(
				status: self::STATUS_UNCHANGED,
				fileId: $fileId,
				padId: $padId,
				external: false,
				forced: false,
				snapshotRev: $snapshotRev,
				currentRev: $currentRev,
			);
		}

		$text = $this->etherpadClient->getText($padId);
		$html = $this->etherpadClient->getHTML($padId);
		if ($force && $snapshotRev >= $currentRev) {
			// force=1 bypasses the cheap revision short-circuit and performs a live content re-check.
			$existingText = $this->padFileService->getTextSnapshotForRestore($currentContent);
			$existingHtml = $this->padFileService->getHtmlSnapshotForRestore($currentContent);
			if ($existingText === $text && $existingHtml === $html) {
				return new PadSyncResult(
					status: self::STATUS_UNCHANGED,
					fileId: $fileId,
					padId: $padId,
					external: false,
					forced: true,
					snapshotRev: $snapshotRev,
					currentRev: $currentRev,
				);
			}
		}

		$updatedContent = $this->padFileService->withExportSnapshot($currentContent, $text, $html, $currentRev);
		$lockRetries = $this->lockRetryService->putContentWithSyncLockRetry($node, $updatedContent);

		return new PadSyncResult(
			status: self::STATUS_UPDATED,
			fileId: $fileId,
			padId: $padId,
			external: false,
			forced: $force,
			snapshotRev: $currentRev,
			lockRetries: $lockRetries,
		);
	}

	private function lockedSyncResponse(
		LockedException $e,
		int $fileId,
		string $absolutePath,
		string $padId,
		string $accessMode,
		bool $isExternal,
		bool $force,
		int $lockRetries,
	): PadSyncResult {
		$this->logger->info('Pad sync deferred because .pad file is locked', [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'path' => $absolutePath,
			'padId' => $padId,
			'accessMode' => $accessMode,
			'external' => $isExternal,
			'force' => $force,
			'lockRetryAttempts' => $lockRetries,
			'exception' => $e,
		]);

		return new PadSyncResult(
			status: self::STATUS_LOCKED,
			fileId: $fileId,
			padId: $padId,
			external: $isExternal,
			forced: $force,
			lockRetries: $lockRetries,
			retryable: true,
		);
	}
}
