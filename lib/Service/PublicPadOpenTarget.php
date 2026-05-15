<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

class PublicPadOpenTarget {
	public function __construct(
		public readonly string $url,
		public readonly string $originalPadUrl,
		public readonly string $cookieHeader,
		public readonly bool $isReadOnlySnapshot,
		public readonly string $snapshotText,
		public readonly string $snapshotHtml,
	) {
	}
}
