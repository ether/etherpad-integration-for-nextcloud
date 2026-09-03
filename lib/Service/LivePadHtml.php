<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * One pad's current content, sanitized and ready for the browser.
 *
 * `isEmpty` is decided here rather than in the viewer: Etherpad answers an
 * empty pad with markup (`<br>`), so "no text" is not the same as "no HTML",
 * and a client checking the string would show an untouched pad as broken.
 */
class LivePadHtml {
	public function __construct(
		public readonly string $html,
		public readonly bool $isEmpty,
	) {
	}
}
