<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Result of looking up the original `.pad` file behind a copy. When
 * `$found` is false the other fields are unset on purpose — see the
 * authorization design in `PadMetadataService::findOriginalForCopy`.
 *
 * Variants:
 *
 * - `$found === false` → fileId and path stay null. The response is
 *   identical for every miss path; the absence of payload data is the
 *   authorization-leak guarantee.
 * - `$found === true`  → fileId and path are guaranteed non-null even
 *   though the type allows null.
 */
class PadOriginalLookup {
	public function __construct(
		public readonly bool $found,
		public readonly ?int $fileId = null,
		public readonly ?string $path = null,
	) {
	}
}
