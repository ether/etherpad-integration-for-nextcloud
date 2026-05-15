<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Lightweight resolve result for the resolve endpoint. The file is not
 * always reachable — the not-found branch can return either fileId or
 * path depending on what the caller passed in, hence both are nullable.
 *
 * Variants:
 *
 * - `$isPad === false`, fileId-only → caller asked by file ID, file did
 *   not resolve. fileId is the requested input; path is null.
 * - `$isPad === false`, path-only → caller asked by path, file did not
 *   resolve or did not exist. path is the requested input; fileId is
 *   null.
 * - `$isPad === false`, both set → file exists but is not a `.pad`.
 * - `$isPad === true` → fileId, path, isPadMime, accessMode, isExternal,
 *   publicOpenUrl all populated. fileId / path are guaranteed non-null
 *   in this branch even though the type allows null.
 */
class PadResolution {
	public function __construct(
		public readonly bool $isPad,
		public readonly ?int $fileId = null,
		public readonly ?string $path = null,
		public readonly bool $isPadMime = false,
		public readonly string $accessMode = '',
		public readonly bool $isExternal = false,
		public readonly string $publicOpenUrl = '',
	) {
	}
}
