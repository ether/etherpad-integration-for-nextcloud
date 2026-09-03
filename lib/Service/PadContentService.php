<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;

/**
 * The read-only view's content endpoint, for signed-in users.
 *
 * Separate from opening on purpose. The open answers what this user may do
 * with the file; this answers what the pad currently says, and the viewer
 * asks again whenever the reader retries. Both questions have their own
 * failure modes, and a reader should be able to tell "you may not see this"
 * from "the pad server did not answer".
 *
 * Every call re-resolves the file and re-checks the binding, so a share
 * withdrawn since the view was opened stops answering, and a `.pad` file
 * edited to name someone else's pad cannot borrow our API key to read it.
 */
class PadContentService {
	public function __construct(
		private PadFileService $padFileService,
		private UserNodeResolver $userNodeResolver,
		private PadFileLockRetryService $lockRetryService,
		private BindingService $bindingService,
		private LivePadHtmlFetcher $livePadHtmlFetcher,
	) {
	}

	public function contentById(string $uid, int $fileId): LivePadHtml {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		// Through the retry, like the open. A sync holds the file for a
		// moment, and reading straight through would turn that moment into
		// an error message and a "Try again" the reader has to press.
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
}
