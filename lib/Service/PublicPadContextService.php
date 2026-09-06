<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\IURLGenerator;
use OCP\Share\IShare;

/**
 * Assembles all data needed by the public viewer API response for one .pad file.
 *
 * The service keeps the controller out of share/file metadata parsing and leaves
 * final HTTP response shaping to the controller.
 */
class PublicPadContextService {
	public function __construct(
		private PublicShareResolver $shareResolver,
		private PadFileService $padFileService,
		private BindingService $bindingService,
		private PublicPadOpenService $publicPadOpenService,
		private LivePadHtmlFetcher $livePadHtmlFetcher,
		private PadFileLockRetryService $lockRetryService,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * What the pad says now, for a read-only visitor of a public link.
	 *
	 * The share is resolved again on every call — the token, its password
	 * gate and the file's membership in the share all have to hold at the
	 * moment of the fetch, not merely at the moment the page was opened.
	 */
	public function resolveContent(string $token, mixed $fileParam, ?IShare $cachedShare = null, mixed $fileIdParam = null): LivePadHtml {
		$share = $this->shareResolver->resolveShare($token, $cachedShare);
		$resolved = $this->shareResolver->resolvePadFile($share, $fileParam, $token, $fileIdParam);
		$node = $resolved->node;

		// See PadContentService for the retry.
		$pad = $this->padFileService->readPad($this->lockRetryService->readContentWithOpenLockRetry($node));

		return $this->livePadHtmlFetcher->fetchForPadFile($pad, (int)$node->getId());
	}

	public function resolve(string $token, mixed $fileParam, ?IShare $cachedShare = null, mixed $fileIdParam = null): PublicPadContext {
		$share = $this->shareResolver->resolveShare($token, $cachedShare);
		$resolved = $this->shareResolver->resolvePadFile($share, $fileParam, $token, $fileIdParam);
		$node = $resolved->node;

		// Same retry as resolveContent(): a sync holding the file for a
		// moment must not become a failed page load.
		$content = $this->lockRetryService->readContentWithOpenLockRetry($node);
		$fileId = (int)$node->getId();

		$pad = $this->padFileService->readPad($content);
		$padId = $pad->padId;
		$accessMode = $pad->accessMode;
		$padUrl = $pad->padUrl;
		$isExternal = $pad->isExternal;

		if (!$isExternal) {
			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);
		}
		$openTarget = $this->publicPadOpenService->open(
			$padId,
			$accessMode,
			$resolved->readOnly,
			$token,
			$isExternal,
			$padUrl,
		);

		return new PublicPadContext(
			$resolved->name,
			$openTarget->url,
			$isExternal,
			$openTarget->isReadOnlyView,
			$openTarget->originalPadUrl,
			// Same rule as the signed-in open: only where one of our own
			// surfaces draws the pad. It carries the id this open resolved
			// to, not what the caller sent: a file renamed or moved inside
			// the share between the two requests still answers to its id,
			// while the path it was opened by no longer names it.
			($openTarget->isReadOnlyView || $isExternal)
				? $this->buildContentUrl($token, $fileId)
				: '',
			$openTarget->cookieHeader,
		);
	}

	private function buildContentUrl(string $token, int $fileId): string {
		return $this->urlGenerator->linkToRoute(
			'etherpad_nextcloud.publicViewer.padContent',
			['token' => $token, 'fileId' => $fileId],
		);
	}
}
