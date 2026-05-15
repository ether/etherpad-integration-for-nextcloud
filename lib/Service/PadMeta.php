<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Full metadata read for a file, used by the meta-by-id endpoint. The
 * file itself is always resolved (fileId / name / path are populated);
 * the pad-specific fields are only meaningful when `$isPad` is true.
 *
 * Variants:
 *
 * - `$isPad === false` → fileId, name, path populated; pad-specific
 *   fields stay at their defaults and should not be read.
 * - `$isPad === true`  → fileId, name, path, isPadMime, accessMode,
 *   isExternal, padId, padUrl, publicOpenUrl all populated.
 */
class PadMeta {
	public function __construct(
		public readonly bool $isPad,
		public readonly int $fileId,
		public readonly string $name,
		public readonly string $path,
		public readonly bool $isPadMime = false,
		public readonly string $accessMode = '',
		public readonly bool $isExternal = false,
		public readonly string $padId = '',
		public readonly string $padUrl = '',
		public readonly string $publicOpenUrl = '',
	) {
	}
}
