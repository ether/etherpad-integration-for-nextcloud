<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

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
	) {
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
			$content,
			$padUrl,
		);

		return new PublicPadContext(
			$resolved->name,
			$openTarget->url,
			$isExternal,
			$openTarget->isReadOnlySnapshot,
			$openTarget->snapshotText,
			$openTarget->snapshotHtml,
			$openTarget->originalPadUrl,
			$openTarget->cookieHeader,
		);
	}
}
