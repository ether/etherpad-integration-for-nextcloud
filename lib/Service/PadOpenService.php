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
			// Not "what the share granted": this is the update permission bit
			// on the file as this user sees it — `(permissions & UPDATE)` —
			// which the share and the mount both feed into. It is not a lock
			// check; Nextcloud treats locks separately, and so does the sync
			// path here.
			//
			// It is the right question anyway, though not because edits would
			// be lost — Etherpad stores the pad itself, and a failed sync
			// leaves a stale copy here rather than lost text. The point is
			// narrower: without write permission in Nextcloud, this open may
			// not issue a session that writes on the pad server.
			$mayWrite = $node->isUpdateable();
			$snapshot = $pad->isExternal
				? $this->snapshotExtractor->extract($content)
				: new SnapshotPayload('', '');
			if (!$pad->isExternal) {
				$this->bindingService->assertConsistentMapping($fileId, $pad->padId, $pad->accessMode);
			}

			return $this->buildOpenContext(
				$uid,
				$displayName,
				$absolutePath,
				$fileId,
				$pad,
				$snapshot,
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
		ParsedPadFile $pad,
		SnapshotPayload $snapshot,
		// Deliberately without a default: a caller that forgets this grants
		// edit access, and nothing would fail to say so.
		bool $mayWrite,
		string $padFileContent
	): PadOpenTarget {
		$padId = $pad->padId;
		$accessMode = $pad->accessMode;
		$isExternal = $pad->isExternal;

		if ($isExternal && $accessMode !== BindingService::ACCESS_PUBLIC) {
			throw new EtherpadClientException('External pad metadata requires public access_mode.');
		}

		// Before any address is built. A protected pad without write
		// permission is answered entirely from the `.pad` file, so it should
		// not depend on the pad server being configured or reachable to say
		// so — and the URL built here would only be discarded.
		if (!$mayWrite && $accessMode === BindingService::ACCESS_PROTECTED) {
			return $this->readOnlySnapshotTarget($path, $fileId, $padId, $accessMode, $padFileContent);
		}

		$originalPadUrl = '';

		if ($isExternal) {
			if ($pad->padUrl === '') {
				throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
			}
			$normalized = $this->externalPadExportFetcher->normalizeAndValidateExternalPublicPadUrl($pad->padUrl);
			$effectivePadUrl = $normalized['pad_url'];
			$originalPadUrl = $normalized['pad_url'];
		} elseif ($mayWrite) {
			$effectivePadUrl = $this->etherpadClient->buildPadUrl($padId);
		} else {
			// A public pad has no session to withhold, so the editable URL is
			// the whole of the access. Etherpad's own read-only view is the
			// one thing that cannot be typed into — and if the pad server
			// cannot say what it is, the snapshot is a better answer than an
			// error, and far better than the editable URL.
			try {
				$effectivePadUrl = $this->etherpadClient->getReadOnlyPadUrl($padId);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not resolve the read-only pad URL; showing the snapshot instead.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'exception' => $e,
				]);
				return $this->readOnlySnapshotTarget($path, $fileId, $padId, $accessMode, $padFileContent);
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
			snapshotText: $isExternal ? $snapshot->text : '',
			snapshotHtml: $isExternal ? $snapshot->html : '',
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
	 * withholding — no client reads it today, which is exactly why it would
	 * be missed.
	 *
	 * Only reached for pads this app holds itself, so there is no external
	 * URL to carry and the snapshot is always the one in this file.
	 */
	private function readOnlySnapshotTarget(
		string $path,
		int $fileId,
		string $padId,
		string $accessMode,
		string $padFileContent
	): PadOpenTarget {
		$snapshot = $this->snapshotExtractor->extract($padFileContent);

		return new PadOpenTarget(
			file: $path,
			fileId: $fileId,
			padId: $padId,
			accessMode: $accessMode,
			padUrl: '',
			isExternal: false,
			originalPadUrl: '',
			snapshotText: $snapshot->text,
			snapshotHtml: $snapshot->html,
			url: '',
			cookieHeader: '',
			isReadOnlySnapshot: true,
		);
	}

}
