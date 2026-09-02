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
		private ExternalPadExportFetcher $externalPadExportFetcher,
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

			$pad = $this->padFileService->readPad($content);
			$padId = $pad->padId;
			$accessMode = $pad->accessMode;
			$padUrl = $pad->padUrl;
			$isExternal = $pad->isExternal;
			// The node is the only place that knows: it is resolved through
			// this user's own view, so its permissions are the ones the
			// share granted them.
			$mayWrite = $node->isUpdateable();
			$snapshot = $isExternal
				? $this->snapshotExtractor->extract($content)
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
				$snapshot->html,
				$mayWrite,
				$content
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
		string $padUrl,
		bool $isExternal,
		string $snapshotText,
		string $snapshotHtml,
		// Deliberately without a default, like everything above it: a caller
		// that forgets this one grants edit access, and nothing would fail
		// to say so.
		bool $mayWrite,
		string $padFileContent
	): PadOpenTarget {
		if ($isExternal && $accessMode !== BindingService::ACCESS_PUBLIC) {
			throw new EtherpadClientException('External pad metadata requires public access_mode.');
		}

		$originalPadUrl = '';

		if ($isExternal) {
			if ($padUrl === '') {
				throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
			}
			$normalized = $this->externalPadExportFetcher->normalizeAndValidateExternalPublicPadUrl($padUrl);
			$effectivePadUrl = $normalized['pad_url'];
			$originalPadUrl = $normalized['pad_url'];
		} else {
			$effectivePadUrl = $this->etherpadClient->buildPadUrl($padId);
		}

		// A share that grants no write permission must not be handed a way to
		// edit. Nextcloud's own read-only shares stop at the file; the pad
		// lives on another host, where the only thing standing between a
		// viewer and the text is what this hands them — a session, or a URL.
		if (!$mayWrite) {
			if ($accessMode === BindingService::ACCESS_PROTECTED) {
				// No session at all. The snapshot in the .pad file is what a
				// viewer gets, exactly as a read-only public link does.
				return $this->readOnlySnapshotTarget(
					$path,
					$fileId,
					$padId,
					$accessMode,
					$isExternal,
					$originalPadUrl,
					$padFileContent,
					$snapshotText,
					$snapshotHtml,
				);
			}

			if (!$isExternal) {
				// A public pad has no session to withhold, so the editable
				// URL is the whole of the access. Etherpad's own read-only
				// view is the one thing that is not editable — and if the pad
				// server cannot say what it is, the snapshot is a better
				// answer than an error, and far better than the editable URL.
				try {
					$effectivePadUrl = $this->etherpadClient->getReadOnlyPadUrl($padId);
				} catch (\Throwable $e) {
					$this->logger->warning('Could not resolve the read-only pad URL; showing the snapshot instead.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'exception' => $e,
					]);
					return $this->readOnlySnapshotTarget(
						$path,
						$fileId,
						$padId,
						$accessMode,
						$isExternal,
						$originalPadUrl,
						$padFileContent,
						$snapshotText,
						$snapshotHtml,
					);
				}
			}
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
			isReadOnlySnapshot: false,
		);
	}
	/**
	 * What a share without write permission gets: the stored snapshot, and
	 * no address of any kind.
	 *
	 * `padUrl` is emptied too. The response ships it as `pad_url`, and
	 * handing back the editable address under a second key would undo the
	 * withholding the caller just did — no client reads it today, which is
	 * exactly why it would be missed.
	 */
	private function readOnlySnapshotTarget(
		string $path,
		int $fileId,
		string $padId,
		string $accessMode,
		bool $isExternal,
		string $originalPadUrl,
		string $padFileContent,
		string $snapshotText,
		string $snapshotHtml
	): PadOpenTarget {
		if (!$isExternal) {
			// Extracted here rather than up front: a sanitize pass over the
			// whole stored document is not worth paying on every open that
			// turns out to be an ordinary editable one.
			$snapshot = $this->snapshotExtractor->extract($padFileContent);
			$snapshotText = $snapshot->text;
			$snapshotHtml = $snapshot->html;
		}

		return new PadOpenTarget(
			file: $path,
			fileId: $fileId,
			padId: $padId,
			accessMode: $accessMode,
			padUrl: '',
			isExternal: $isExternal,
			originalPadUrl: $originalPadUrl,
			snapshotText: $snapshotText,
			snapshotHtml: $snapshotHtml,
			url: '',
			cookieHeader: '',
			isReadOnlySnapshot: true,
		);
	}

}
