<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadLostException;

/**
 * Applies public-share-specific open rules for internal, protected and external pads.
 *
 * Public protected write access opens as the visitor's own Etherpad author,
 * or the link's (PublicLinkVisitors);
 * read-only protected access gets no pad address at all and is answered by
 * this app's own viewer, which loads the content separately.
 */
class PublicPadOpenService {
	/**
	 * Nothing revokes this one - a visitor never logs out - so it is the
	 * whole lifetime of a withdrawn share's write access. Shorter than an
	 * authenticated session for that reason.
	 */
	private const PUBLIC_SHARE_SESSION_TTL_SECONDS = 10800;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private ManagedPadLifecycle $padLifecycle,
		private ExternalPadExportFetcher $externalPadExportFetcher,
		private PadSessionService $padSessionService,
		private PublicLinkVisitors $visitors,
	) {
	}

	public function open(ParsedPadFile $pad, bool $readOnly, string $token): PublicPadOpenTarget {
		if ($pad->isExternal) {
			$normalized = $this->externalPadExportFetcher->normalizeAndValidateExternalPublicPadUrl($pad->externalPadUrl());
			return new PublicPadOpenTarget(
				$normalized['pad_url'],
				$normalized['pad_url'],
				'',
				false,
			);
		}

		$padId = $pad->padId;
		// A pad Etherpad has lost would come back empty, or not at all: only
		// the file's owner can make a new pad from its content. A reader is
		// shown what the pad server has, as ever. Only a definite answer
		// stops the open.
		if (!$readOnly && $this->padLifecycle->isKnownLost($padId, $pad->accessMode, $pad)) {
			throw new PadLostException('Etherpad has lost the pad of this file.');
		}

		if ($pad->accessMode === BindingService::ACCESS_PROTECTED) {
			if ($readOnly) {
				return new PublicPadOpenTarget('', '', '', true);
			}

			$opener = $this->visitors->openerFor($token);
			$openContext = $this->padSessionService->createProtectedOpenContext(
				$opener->uid,
				$opener->displayName,
				$padId,
				self::PUBLIC_SHARE_SESSION_TTL_SECONDS,
				$opener->authorId,
			);
			$this->visitors->rememberAuthor($token, $opener, $openContext['authorId']);

			return new PublicPadOpenTarget(
				$openContext['url'],
				'',
				$this->padSessionService->buildSetCookieHeader($openContext['cookie']),
				false,
			);
		}

		if ($readOnly) {
			return new PublicPadOpenTarget($this->etherpadClient->getReadOnlyPadUrl($padId), '', '', false);
		}

		return new PublicPadOpenTarget($this->etherpadClient->buildPadUrl($padId), '', '', false);
	}
}
