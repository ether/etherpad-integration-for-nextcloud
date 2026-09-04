<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * The read-only view's content endpoint, for signed-in users.
 *
 * Separate from opening on purpose: the open answers what this user may do
 * with the file, this answers what the pad says, and a reader should be
 * able to tell "you may not see this" from "the pad server did not
 * answer". Every call re-resolves the file, so a share withdrawn since the
 * view was opened stops answering.
 */
class PadContentService {
	public function __construct(
		private PadFileService $padFileService,
		private UserNodeResolver $userNodeResolver,
		private PadFileLockRetryService $lockRetryService,
		private LivePadHtmlFetcher $livePadHtmlFetcher,
	) {
	}

	public function contentById(string $uid, int $fileId): LivePadHtml {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		// Through the retry, like the open: a sync holds the file for a
		// moment, and that moment must not surface as an error.
		$pad = $this->padFileService->readPad($this->lockRetryService->readContentWithOpenLockRetry($node));

		return $this->livePadHtmlFetcher->fetchForPadFile($pad, (int)$node->getId());
	}
}
