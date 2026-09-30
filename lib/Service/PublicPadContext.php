<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

class PublicPadContext {
	public function __construct(
		public string $title,
		public string $url,
		public bool $isExternal,
		public bool $isReadOnlyView,
		public string $originalPadUrl,
		/**
		 * The address the viewer loads the pad from, which carries the
		 * share token, and the session cookie: both open the pad. Private
		 * behind their methods, for the reason given at ParsedPadFile - a
		 * public field of an argument ends up in a serialized trace.
		 */
		private string $contentUrl,
		private string $cookieHeader,
	) {
	}

	public function contentUrl(): string {
		return $this->contentUrl;
	}

	public function cookieHeader(): string {
		return $this->cookieHeader;
	}
}
