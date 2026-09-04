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
 * `isEmpty` is decided server-side because Etherpad answers an untouched
 * pad with markup (`<br>`), which a client checking the string would show
 * as broken rather than as empty.
 */
class LivePadHtml {
	public function __construct(
		public readonly string $html,
		public readonly bool $isEmpty,
	) {
	}
}
