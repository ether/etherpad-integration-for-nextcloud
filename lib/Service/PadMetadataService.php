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
		private ExternalPadExportFetcher $externalPadExportFetcher,
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
		$located = $this->locateOriginal($uid, $fileId);
		if ($located === null) {
			return new PadOriginalLookup(found: false);
		}

		return new PadOriginalLookup(
			found: true,
			fileId: $located['fileId'],
			path: $located['path'],
		);
	}

	/**
	 * Records in the copy's own frontmatter that it defers to the original,
	 * so later opens land on the pad instead of the recovery card.
	 *
	 * Runs the same lookup as `findOriginalForCopy` rather than trusting a
	 * pad ID from the request, so the marker can only ever be written for a
	 * pad the requester already reads. A miss writes nothing and reports
	 * `found: false`, which leaves the caller on the recovery card.
	 *
	 * The marker is the pad ID the copy already carries: a copy is a byte
	 * copy, so its `pad_id` is the original's. Naming the target explicitly
	 * rather than setting a flag keeps the alias pointing at the same pad if
	 * this file later gets a pad of its own.
	 *
	 * @throws LockedException
	 */
	public function markAsAliasOfOriginal(string $uid, int $fileId): PadOriginalLookup {
		$located = $this->locateOriginal($uid, $fileId);
		if ($located === null) {
			return new PadOriginalLookup(found: false);
		}

		$node = $located['node'];
		$parsed = $this->padFileService->readPad($this->lockRetryService->readContentWithOpenLockRetry($node));
		if ($parsed->aliasOfPadId !== $located['padId']) {
			$frontmatter = $parsed->frontmatter;
			$frontmatter['alias_of_pad_id'] = $located['padId'];
			$this->lockRetryService->putContentWithSyncLockRetry(
				$node,
				$this->padFileService->serialize($frontmatter, $parsed->body),
			);
		}

		return new PadOriginalLookup(
			found: true,
			fileId: $located['fileId'],
			path: $located['path'],
		);
	}

	/**
	 * @return array{node:File,padId:string,fileId:int,path:string}|null
	 */
	private function locateOriginal(string $uid, int $fileId): ?array {
		try {
			$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		} catch (NotFoundException) {
			return null;
		}
		if (!str_ends_with(strtolower($node->getName()), '.pad')) {
			return null;
		}

		try {
			$padId = $this->padFileService->readPad((string)$node->getContent())->padId;
		} catch (\Throwable) {
			return null;
		}
		if ($padId === '' || str_starts_with($padId, 'ext.')) {
			return null;
		}

		$binding = $this->bindingService->findByPadId($padId, BindingService::STATE_ACTIVE);
		if ($binding === null) {
			return null;
		}

		$boundFileId = (int)$binding['file_id'];
		if ($boundFileId <= 0 || $boundFileId === $fileId) {
			return null;
		}

		try {
			$originalNode = $this->userNodeResolver->resolveUserFileNodeById($uid, $boundFileId);
		} catch (NotFoundException) {
			return null;
		}

		return [
			'node' => $node,
			'padId' => $padId,
			'fileId' => $boundFileId,
			'path' => $this->userNodeResolver->toUserAbsolutePath($uid, $originalNode),
		];
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
			isPadMime: $node->getMimeType() === 'application/x-etherpad-nextcloud',
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
			isPadMime: $node->getMimeType() === 'application/x-etherpad-nextcloud',
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
				// The same question the open path asks, and the same caveat:
				// this is whether the file is writable, not what the share
				// granted. Answering it in one service and not the other
				// would leave the rule true of one endpoint and claimed of
				// both.
				if ($node->isUpdateable()) {
					$publicOpenUrl = $this->resolvePublicOpenUrl($padId, $padUrl, $isExternal);
					if ($publicOpenUrl !== '') {
						$padUrl = $publicOpenUrl;
					}
				} else {
					// No fall-through to the stored address. Without a
					// read-only URL there is no address this share may be
					// given, and leaving the editable one in `pad_url` would
					// hand it over precisely when the lookup that was meant
					// to avoid that has failed.
					$publicOpenUrl = $this->resolveReadOnlyOpenUrl($padId, $padUrl, $isExternal);
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

	/**
	 * What a share without write permission is told a public pad's address
	 * is: Etherpad's read-only view.
	 *
	 * Presentation rather than enforcement — a public pad is editable by
	 * anyone holding its id, and its id is in the `.pad` file the recipient
	 * may read. It is still the address that matches what the share says,
	 * and an unreachable pad server means no address at all rather than the
	 * editable one.
	 */
	private function resolveReadOnlyOpenUrl(string $padId, string $padUrl, bool $isExternal): string {
		if ($isExternal || $padId === '') {
			return $this->resolvePublicOpenUrl($padId, $padUrl, $isExternal);
		}

		try {
			return $this->etherpadClient->getReadOnlyPadUrl($padId);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not resolve the read-only pad URL for metadata.', [
				'app' => 'etherpad_nextcloud',
				'exception' => $e,
			]);
			return '';
		}
	}

	private function resolvePublicOpenUrl(string $padId, string $padUrl, bool $isExternal): string {
		if ($isExternal && $padUrl !== '') {
			$normalized = $this->externalPadExportFetcher->normalizeAndValidateExternalPublicPadUrl($padUrl);
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
