<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Result of a `.pad` frontmatter initialization. The `$status` field is one
 * of {@see PadInitializationService}'s STATUS_* constants and tells the
 * caller whether the frontmatter was already present (no write happened) or
 * was bootstrapped (file was rewritten).
 */
class PadInitializationResult {
	public function __construct(
		public readonly string $status,
		public readonly string $file,
		public readonly int $fileId,
		public readonly string $padId,
		public readonly string $accessMode,
	) {
	}
}
