<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadMetadataService {
	public function __construct(
		private PadFileService $padFileService,
		private PathNormalizer $padPaths,
		private UserNodeResolver $userNodeResolver,
		private PadFileLockRetryService $lockRetryService,
		private EtherpadClient $etherpadClient,
		private BindingService $bindingService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Looks up whether the frontmatter `pad_id` of an orphaned `.pad` file
	 * points at another `.pad` in the requester's userspace. Used to offer
	 * "Open the original" in the recovery UI when a copy was made.
	 *
	 * Authorization: the response is identical for every miss path — no
	 * binding, binding for ext.* pad ID, binding owned by another user,
	 * trashed / pending-delete binding, frontmatter unreadable. The presence
	 * of the `found: true` payload itself is therefore the only signal, and
	 * it is only emitted when the requester can already read the bound file
	 * (gated by UserNodeResolver). This means a crafted frontmatter cannot
	 * be used to probe for `.pad` files in other users' accounts.
	 */
	public function findOriginalForCopy(string $uid, int $fileId): PadOriginalLookup {
		try {
			$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		} catch (NotFoundException) {
			return new PadOriginalLookup(found: false);
		}
		if (!str_ends_with(strtolower($node->getName()), '.pad')) {
			return new PadOriginalLookup(found: false);
		}

		$padId = '';
		try {
			$padId = $this->padFileService->readPad((string)$node->getContent())->padId;
		} catch (\Throwable) {
			return new PadOriginalLookup(found: false);
		}
		if ($padId === '' || str_starts_with($padId, 'ext.')) {
			return new PadOriginalLookup(found: false);
		}

		$binding = $this->bindingService->findByPadId($padId, BindingService::STATE_ACTIVE);
		if ($binding === null) {
			return new PadOriginalLookup(found: false);
		}

		$boundFileId = (int)$binding['file_id'];
		if ($boundFileId <= 0 || $boundFileId === $fileId) {
			return new PadOriginalLookup(found: false);
		}

		try {
			$originalNode = $this->userNodeResolver->resolveUserFileNodeById($uid, $boundFileId);
		} catch (NotFoundException) {
			return new PadOriginalLookup(found: false);
		}

		return new PadOriginalLookup(
			found: true,
			fileId: $boundFileId,
			path: $this->userNodeResolver->toUserAbsolutePath($uid, $originalNode),
		);
	}

	/**
	 * @throws NotFoundException
	 * @throws LockedException
	 */
	public function metaById(string $uid, int $fileId): PadMeta {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		return $this->buildMeta($node, $absolutePath);
	}

	public function resolve(string $uid, int $fileId = 0, string $file = ''): PadResolution {
		$resolvedFileId = $fileId;
		if ($resolvedFileId > 0) {
			try {
				$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $resolvedFileId);
				$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
			} catch (NotFoundException) {
				return new PadResolution(isPad: false, fileId: $resolvedFileId);
			}
		} else {
			$requestedPath = $this->padPaths->normalizeViewerFilePath($file);
			if ($requestedPath === '') {
				throw new \InvalidArgumentException('Invalid file path.');
			}

			try {
				$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $requestedPath);
			} catch (NotFoundException) {
				return new PadResolution(isPad: false, path: $requestedPath);
			}

			$resolvedFileId = (int)$node->getId();
			if ($resolvedFileId <= 0) {
				return new PadResolution(isPad: false, path: $requestedPath);
			}
			$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		}

		if (!str_ends_with(strtolower($normalizedPath), '.pad')) {
			return new PadResolution(isPad: false, fileId: $resolvedFileId, path: $normalizedPath);
		}

		return $this->buildResolve($node, $resolvedFileId, $normalizedPath);
	}

	/**
	 * @throws LockedException
	 */
	private function buildMeta(File $node, string $absolutePath): PadMeta {
		$fileId = (int)$node->getId();
		if ($fileId <= 0) {
			throw new \RuntimeException('Could not resolve file ID.');
		}

		if (!str_ends_with(strtolower($absolutePath), '.pad')) {
			return new PadMeta(
				isPad: false,
				fileId: $fileId,
				name: $node->getName(),
				path: $absolutePath,
			);
		}

		$metadata = $this->readPadMetadata($node, $fileId, $absolutePath, true, 'Pad meta parse skipped');

		return new PadMeta(
			isPad: true,
			fileId: $fileId,
			name: $node->getName(),
			path: $absolutePath,
			isPadMime: (string)$node->getMimeType() === 'application/x-etherpad-nextcloud',
			accessMode: $metadata['access_mode'],
			isExternal: $metadata['is_external'],
			padId: $metadata['pad_id'],
			padUrl: $metadata['pad_url'],
			publicOpenUrl: $metadata['public_open_url'],
		);
	}

	private function buildResolve(File $node, int $fileId, string $absolutePath): PadResolution {
		$metadata = $this->readPadMetadata($node, $fileId, $absolutePath, false, 'Pad resolve metadata parse skipped');

		return new PadResolution(
			isPad: true,
			fileId: $fileId,
			path: $absolutePath,
			isPadMime: (string)$node->getMimeType() === 'application/x-etherpad-nextcloud',
			accessMode: $metadata['access_mode'],
			isExternal: $metadata['is_external'],
			publicOpenUrl: $metadata['public_open_url'],
		);
	}

	/** @return array{access_mode:string,is_external:bool,pad_id:string,pad_url:string,public_open_url:string} */
	private function readPadMetadata(File $node, int $fileId, string $absolutePath, bool $retryLockedRead, string $logMessage): array {
		$accessMode = '';
		$isExternal = false;
		$publicOpenUrl = '';
		$padUrl = '';
		$padId = '';

		try {
			$content = $retryLockedRead
				? $this->lockRetryService->readContentWithOpenLockRetry($node)
				: (string)$node->getContent();
			$pad = $this->padFileService->readPad((string)$content);
			$padId = $pad->padId;
			$accessMode = $pad->accessMode;
			$padUrl = $pad->padUrl;
			$isExternal = $pad->isExternal;

			if ($accessMode === BindingService::ACCESS_PUBLIC) {
				$publicOpenUrl = $this->resolvePublicOpenUrl($padId, $padUrl, $isExternal);
				if ($publicOpenUrl !== '') {
					$padUrl = $publicOpenUrl;
				}
			}
		} catch (LockedException $e) {
			if ($retryLockedRead) {
				throw $e;
			}
			$this->logSkippedMetadata($logMessage, $fileId, $absolutePath, $e);
		} catch (\Throwable $e) {
			$this->logSkippedMetadata($logMessage, $fileId, $absolutePath, $e);
		}

		return [
			'access_mode' => $accessMode,
			'is_external' => $isExternal,
			'pad_id' => $padId,
			'pad_url' => $padUrl,
			'public_open_url' => $publicOpenUrl,
		];
	}

	private function resolvePublicOpenUrl(string $padId, string $padUrl, bool $isExternal): string {
		if ($isExternal && $padUrl !== '') {
			$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
			return (string)$normalized['pad_url'];
		}
		if ($padId !== '') {
			return $this->etherpadClient->buildPadUrl($padId);
		}
		return '';
	}

	private function logSkippedMetadata(string $message, int $fileId, string $absolutePath, \Throwable $e): void {
		$this->logger->debug($message, [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'path' => $absolutePath,
			'exception' => $e,
		]);
	}
}
