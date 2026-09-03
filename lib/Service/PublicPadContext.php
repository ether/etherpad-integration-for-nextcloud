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
		public string $contentUrl,
		public string $cookieHeader,
	) {
	}
}
