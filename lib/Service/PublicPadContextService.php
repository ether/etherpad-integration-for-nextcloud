<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
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
	public function resolveContent(string $token, mixed $fileParam, ?IShare $cachedShare = null): LivePadHtml {
		$share = $this->shareResolver->resolveShare($token, $cachedShare);
		$resolved = $this->shareResolver->resolvePadFile($share, $fileParam, $token);
		$node = $resolved->node;

		// See PadContentService: a sync holding the file must not surface
		// as a failed content fetch.
		$pad = $this->padFileService->readPad($this->lockRetryService->readContentWithOpenLockRetry($node));
		if ($pad->isExternal) {
			if ($pad->accessMode !== BindingService::ACCESS_PUBLIC) {
				throw new EtherpadClientException('External pad metadata requires public access_mode.');
			}
			if ($pad->padUrl === '') {
				throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
			}
			return $this->livePadHtmlFetcher->fetchExternal($pad->padUrl);
		}

		$this->bindingService->assertConsistentMapping((int)$node->getId(), $pad->padId, $pad->accessMode);

		return $this->livePadHtmlFetcher->fetchInternal($pad->padId);
	}

	public function resolve(string $token, mixed $fileParam, ?IShare $cachedShare = null): PublicPadContext {
		$share = $this->shareResolver->resolveShare($token, $cachedShare);
		$resolved = $this->shareResolver->resolvePadFile($share, $fileParam, $token);
		$node = $resolved->node;

		$content = (string)$node->getContent();
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
			// surfaces draws the pad. The `file` parameter travels with it
			// so the retry resolves the same node inside the share.
			($openTarget->isReadOnlyView || $isExternal)
				? $this->buildContentUrl($token, $fileParam)
				: '',
			$openTarget->cookieHeader,
		);
	}

	private function buildContentUrl(string $token, mixed $fileParam): string {
		$parameters = ['token' => $token];
		if (is_string($fileParam) && $fileParam !== '') {
			$parameters['file'] = $fileParam;
		}

		return $this->urlGenerator->linkToRoute('etherpad_nextcloud.publicViewer.padContent', $parameters);
	}
}
