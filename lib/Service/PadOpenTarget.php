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
 * public flow answers a share token and has none of those fields.
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
		public readonly string $url,
		public readonly string $cookieHeader,
		// Required, unlike every optional convenience above it: these decide
		// whether somebody may edit, and a default would let a future
		// construction site grant that by forgetting.
		//
		// Two fields, because they are two things. A public pad opened
		// read-only is Etherpad's own read-only view in an iframe, not this
		// app's viewer — but it is still not writable, and anything that
		// follows from "may not write" has to follow from that rather than
		// from which surface is shown.
		public readonly bool $isReadOnlyView,
		public readonly bool $mayWrite,
	) {
	}
}
