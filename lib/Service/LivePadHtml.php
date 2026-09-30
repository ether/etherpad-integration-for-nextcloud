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
 *
 * The content is a private field behind a method, for the reason given at
 * ParsedPadFile: a public field of an argument ends up in a serialized
 * trace.
 */
class LivePadHtml {
	public function __construct(
		private readonly string $html,
		public readonly bool $isEmpty,
	) {
	}

	public function html(): string {
		return $this->html;
	}
}
