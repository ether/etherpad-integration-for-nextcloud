<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadMetadataService {
	public function __construct(
		private PadFileService $padFileService,
		private PadPathService $padPaths,
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
	 *
	 * @return array{found:false}|array{found:true,file_id:int,path:string}
	 */
	public function findOriginalForCopy(string $uid, int $fileId): array {
		try {
			$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		} catch (NotFoundException) {
			return ['found' => false];
		}
		if (!str_ends_with(strtolower($node->getName()), '.pad')) {
			return ['found' => false];
		}

		$padId = '';
		try {
			$content = (string)$node->getContent();
			$parsed = $this->padFileService->parsePadFile($content);
			$meta = $this->padFileService->extractPadMetadata($parsed['frontmatter']);
			$padId = (string)($meta['pad_id'] ?? '');
		} catch (\Throwable) {
			return ['found' => false];
		}
		if ($padId === '' || str_starts_with($padId, 'ext.')) {
			return ['found' => false];
		}

		$binding = $this->bindingService->findByPadId($padId, BindingService::STATE_ACTIVE);
		if ($binding === null) {
			return ['found' => false];
		}

		$boundFileId = (int)$binding['file_id'];
		if ($boundFileId <= 0 || $boundFileId === $fileId) {
			return ['found' => false];
		}

		try {
			$originalNode = $this->userNodeResolver->resolveUserFileNodeById($uid, $boundFileId);
		} catch (NotFoundException) {
			return ['found' => false];
		}

		return [
			'found' => true,
			'file_id' => $boundFileId,
			'path' => $this->userNodeResolver->toUserAbsolutePath($uid, $originalNode),
		];
	}

	/**
	 * @return array{is_pad:false,file_id:int,name:string,path:string}|array{is_pad:true,is_pad_mime:bool,file_id:int,name:string,path:string,access_mode:string,is_external:bool,pad_id:string,pad_url:string,public_open_url:string}
	 * @throws NotFoundException
	 * @throws LockedException
	 */
	public function metaById(string $uid, int $fileId): array {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		return $this->buildMeta($node, $absolutePath);
	}

	/** @return array{is_pad:false,file_id?:int,path?:string}|array{is_pad:true,is_pad_mime:bool,file_id:int,path:string,access_mode:string,is_external:bool,public_open_url:string} */
	public function resolve(string $uid, int $fileId = 0, string $file = ''): array {
		$resolvedFileId = $fileId;
		if ($resolvedFileId > 0) {
			try {
				$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $resolvedFileId);
				$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
			} catch (NotFoundException) {
				return ['is_pad' => false, 'file_id' => $resolvedFileId];
			}
		} else {
			$requestedPath = $this->padPaths->normalizeViewerFilePath($file);
			if ($requestedPath === '') {
				throw new \InvalidArgumentException('Invalid file path.');
			}

			try {
				$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $requestedPath);
			} catch (NotFoundException) {
				return ['is_pad' => false, 'path' => $requestedPath];
			}

			$resolvedFileId = (int)$node->getId();
			if ($resolvedFileId <= 0) {
				return ['is_pad' => false, 'path' => $requestedPath];
			}
			$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		}

		if (!str_ends_with(strtolower($normalizedPath), '.pad')) {
			return ['is_pad' => false, 'file_id' => $resolvedFileId, 'path' => $normalizedPath];
		}

		return $this->buildResolve($node, $resolvedFileId, $normalizedPath);
	}

	/**
	 * @return array{is_pad:false,file_id:int,name:string,path:string}|array{is_pad:true,is_pad_mime:bool,file_id:int,name:string,path:string,access_mode:string,is_external:bool,pad_id:string,pad_url:string,public_open_url:string}
	 * @throws LockedException
	 */
	private function buildMeta(File $node, string $absolutePath): array {
		$fileId = (int)$node->getId();
		if ($fileId <= 0) {
			throw new \RuntimeException('Could not resolve file ID.');
		}

		if (!str_ends_with(strtolower($absolutePath), '.pad')) {
			return [
				'is_pad' => false,
				'file_id' => $fileId,
				'name' => $node->getName(),
				'path' => $absolutePath,
			];
		}

		$metadata = $this->readPadMetadata($node, $fileId, $absolutePath, true, 'Pad meta parse skipped');

		return [
			'is_pad' => true,
			'is_pad_mime' => (string)$node->getMimeType() === 'application/x-etherpad-nextcloud',
			'file_id' => $fileId,
			'name' => $node->getName(),
			'path' => $absolutePath,
			'access_mode' => $metadata['access_mode'],
			'is_external' => $metadata['is_external'],
			'pad_id' => $metadata['pad_id'],
			'pad_url' => $metadata['pad_url'],
			'public_open_url' => $metadata['public_open_url'],
		];
	}

	/** @return array{is_pad:true,is_pad_mime:bool,file_id:int,path:string,access_mode:string,is_external:bool,public_open_url:string} */
	private function buildResolve(File $node, int $fileId, string $absolutePath): array {
		$metadata = $this->readPadMetadata($node, $fileId, $absolutePath, false, 'Pad resolve metadata parse skipped');

		return [
			'is_pad' => true,
			'is_pad_mime' => (string)$node->getMimeType() === 'application/x-etherpad-nextcloud',
			'file_id' => $fileId,
			'path' => $absolutePath,
			'access_mode' => $metadata['access_mode'],
			'is_external' => $metadata['is_external'],
			'public_open_url' => $metadata['public_open_url'],
		];
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
			$parsed = $this->padFileService->parsePadFile((string)$content);
			$frontmatter = $parsed['frontmatter'];
			$meta = $this->padFileService->extractPadMetadata($frontmatter);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($frontmatter, $padId);

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
