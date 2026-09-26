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
use OCA\EtherpadNextcloud\Util\SafeError;
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
		private SettleOnOpen $settleOnOpen,
		private EtherpadClient $etherpadClient,
		private ExternalPadExportFetcher $externalPadExportFetcher,
		private PadSessionService $padSessionService,
		private LoggerInterface $logger,
		private BoundPadResolver $boundPads,
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
		if (!$pad->isExternal) {
			$bound = $this->settleOnOpen->settleThenResolve($node, $fileId, $pad);
			if ($bound !== $pad && $mayWrite) {
				$this->followRow($node, $fileId, $content, $bound);
			}
			$pad = $bound;
		}

		return $this->buildOpenContext($uid, $displayName, $absolutePath, $fileId, $pad, $mayWrite);
	}

	/**
	 * The file named the pad its row replaced, and whoever opens it may
	 * write it: it is written to name the row's pad, once, over what this
	 * open read and nothing newer. Should that not work, the row's pad
	 * opens all the same, and the next open or sync tries again.
	 */
	private function followRow(File $node, int $fileId, string $read, ParsedPadFile $bound): void {
		try {
			if ($node->getContent() !== $read) {
				return;
			}
			$node->putContent($this->padFileService->serialize($bound->frontmatter, $bound->body));
			$this->boundPads->repaired($fileId);
		} catch (\Throwable $e) {
			$this->logger->debug('Could not rewrite a .pad file to its row\'s pad; the row\'s pad opens all the same.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
		}
	}

	private function buildOpenContext(
		string $uid,
		string $displayName,
		string $path,
		int $fileId,
		ParsedPadFile $pad,
		// Deliberately without a default: a caller that forgets this grants
		// edit access, and nothing would fail to say so.
		bool $mayWrite
	): PadOpenTarget {
		$padId = $pad->padId;
		$accessMode = $pad->accessMode;
		$isExternal = $pad->isExternal;

		$externalPadUrl = $isExternal ? $pad->externalPadUrl() : '';

		// Before any address is built. A protected pad without write
		// permission gets no address of any kind, so this should not depend
		// on the pad server being reachable to say so — and the URL built
		// here would only be discarded.
		if (!$mayWrite && $accessMode === BindingService::ACCESS_PROTECTED) {
			return $this->readOnlyViewTarget($path, $fileId, $padId, $accessMode);
		}

		$originalPadUrl = '';

		if ($isExternal) {
			$normalized = $this->externalPadExportFetcher->normalizeAndValidateExternalPublicPadUrl($externalPadUrl);
			$effectivePadUrl = $normalized['pad_url'];
			$originalPadUrl = $normalized['pad_url'];
		} elseif ($mayWrite) {
			$effectivePadUrl = $this->etherpadClient->buildPadUrl($padId);
		} else {
			// A public pad has no session to withhold, so the editable URL is
			// the whole of the access. Etherpad's own read-only view is the
			// one thing that cannot be typed into — and if the pad server
			// cannot say what it is, this app's own read-only view is a
			// better answer than an error, and far better than the editable
			// URL.
			try {
				$effectivePadUrl = $this->etherpadClient->getReadOnlyPadUrl($padId);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not resolve the read-only pad URL; showing the read-only view instead.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					...SafeError::context($e),
				]);
				return $this->readOnlyViewTarget($path, $fileId, $padId, $accessMode);
			}
		}

		$cookieHeader = '';
		if ($accessMode === BindingService::ACCESS_PROTECTED) {
			$openContext = $this->padSessionService->createProtectedOpenContext($uid, $displayName, $padId);
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
			url: $url,
			cookieHeader: $cookieHeader,
			isReadOnlyView: false,
			mayWrite: $mayWrite,
		);
	}

	/**
	 * What a share without write permission gets: no address of any kind.
	 *
	 * `padUrl` is emptied too. The response ships it as `pad_url`, and
	 * handing back the editable address under a second key would undo the
	 * withholding — no client reads it today, which is exactly why it would
	 * be missed. The content arrives over the separate content endpoint,
	 * which never discloses an address either.
	 *
	 * Only reached for pads this app holds itself, so there is no external
	 * URL to carry.
	 */
	private function readOnlyViewTarget(string $path, int $fileId, string $padId, string $accessMode): PadOpenTarget {
		return new PadOpenTarget(
			file: $path,
			fileId: $fileId,
			padId: $padId,
			accessMode: $accessMode,
			padUrl: '',
			isExternal: false,
			originalPadUrl: '',
			url: '',
			cookieHeader: '',
			isReadOnlyView: true,
			mayWrite: false,
		);
	}

}
