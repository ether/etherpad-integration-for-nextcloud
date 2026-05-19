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
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadOpenService {
	public function __construct(
		private PadFileService $padFileService,
		private PathNormalizer $padPaths,
		private UserNodeResolver $userNodeResolver,
		private PadFileLockRetryService $lockRetryService,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private PadSessionService $padSessionService,
		private SnapshotExtractor $snapshotExtractor,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws NotFoundException
	 */
	public function openByPath(string $uid, string $displayName, string $file): PadOpenTarget {
		$path = $this->padPaths->normalizeViewerFilePath($file);
		if ($path === '') {
			throw new \InvalidArgumentException('Invalid file path.');
		}
		$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $path);
		$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		return $this->openNode($uid, $displayName, $node, $absolutePath);
	}

	/**
	 * @throws NotFoundException
	 */
	public function openById(string $uid, string $displayName, int $fileId): PadOpenTarget {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		return $this->openNode($uid, $displayName, $node, $absolutePath);
	}

	/**
	 * @throws BindingException
	 * @throws EtherpadClientException
	 * @throws LockedException
	 * @throws PadFileFormatException
	 */
	private function openNode(string $uid, string $displayName, File $node, string $absolutePath): PadOpenTarget {
		try {
			$content = $this->lockRetryService->readContentWithOpenLockRetry($node);
			$fileId = (int)$node->getId();
			if ($fileId <= 0) {
				throw new \RuntimeException('Could not resolve file ID.');
			}

			$pad = $this->padFileService->readPad((string)$content);
			$padId = $pad->padId;
			$accessMode = $pad->accessMode;
			$padUrl = $pad->padUrl;
			$isExternal = $pad->isExternal;
			$snapshot = $isExternal
				? $this->snapshotExtractor->extract((string)$content)
				: new SnapshotPayload('', '');
			if (!$isExternal) {
				$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);
			}

			return $this->buildOpenContext(
				$uid,
				$displayName,
				$absolutePath,
				$fileId,
				$padId,
				$accessMode,
				$padUrl,
				$isExternal,
				$snapshot->text,
				$snapshot->html
			);
		} catch (LockedException $e) {
			$this->logger->info('Pad open deferred because .pad file is locked', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$node->getId(),
				'path' => $absolutePath,
				'exception' => $e,
			]);
			throw $e;
		}
	}

	private function buildOpenContext(
		string $uid,
		string $displayName,
		string $path,
		int $fileId,
		string $padId,
		string $accessMode,
		string $padUrl = '',
		bool $isExternal = false,
		string $snapshotText = '',
		string $snapshotHtml = ''
	): PadOpenTarget {
		if ($isExternal && $accessMode !== BindingService::ACCESS_PUBLIC) {
			throw new EtherpadClientException('External pad metadata requires public access_mode.');
		}

		$effectivePadUrl = '';
		$originalPadUrl = '';

		if ($isExternal) {
			if ($padUrl === '') {
				throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
			}
			$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
			$effectivePadUrl = $normalized['pad_url'];
			$originalPadUrl = $normalized['pad_url'];
		} else {
			$effectivePadUrl = $this->etherpadClient->buildPadUrl($padId);
		}

		$cookieHeader = '';
		if ($accessMode === BindingService::ACCESS_PROTECTED) {
			$openContext = $this->padSessionService->createProtectedOpenContext($uid, $displayName, $padId, 3600);
			$url = $openContext['url'];
			$cookieHeader = $this->padSessionService->buildSetCookieHeader($openContext['cookie']);
		} else {
			// Public pads intentionally open without an Etherpad session so
			// Nextcloud user identity is not shared unless protected access needs it.
			$url = $effectivePadUrl;
		}

		return new PadOpenTarget(
			file: $path,
			fileId: $fileId,
			padId: $padId,
			accessMode: $accessMode,
			padUrl: $effectivePadUrl,
			isExternal: $isExternal,
			originalPadUrl: $originalPadUrl,
			snapshotText: $isExternal ? $snapshotText : '',
			snapshotHtml: $isExternal ? $snapshotHtml : '',
			url: $url,
			cookieHeader: $cookieHeader,
		);
	}
}
