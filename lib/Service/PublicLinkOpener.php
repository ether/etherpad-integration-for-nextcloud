<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/** Who a public link's open is made as (PublicLinkVisitors). */
class PublicLinkOpener {
	public function __construct(
		/** Private: it carries the share's token, as PublicPadContext's address does. */
		private readonly string $uid,
		/** The name Etherpad is given: none for a visitor, who sets their own. */
		public readonly string $displayName,
		/** The visitor's Etherpad author as their session remembers it, or ''. */
		public readonly string $authorId,
	) {
	}

	/** `public-share:<token>:<visitor>`, or `public-share:<token>` for the link itself. */
	public function uid(): string {
		return $this->uid;
	}
}
