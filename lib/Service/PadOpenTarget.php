<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Outcome of an authenticated `.pad` open. Kept separate from
 * `PublicPadOpenTarget`: the internal flow always carries the bound
 * `pad_id` / `access_mode` and the file's userspace path, while the
 * public flow can degrade to a read-only snapshot payload that has
 * none of those fields.
 */
class PadOpenTarget {
	public function __construct(
		public readonly string $file,
		public readonly int $fileId,
		public readonly string $padId,
		public readonly string $accessMode,
		public readonly string $padUrl,
		public readonly bool $isExternal,
		public readonly string $originalPadUrl,
		public readonly string $snapshotText,
		public readonly string $snapshotHtml,
		public readonly string $url,
		public readonly string $cookieHeader,
	) {
	}
}
