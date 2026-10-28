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
		/** The pad's addresses, private as in PadOpenTarget. */
		private readonly string $padUrl = '',
		private readonly string $publicOpenUrl = '',
	) {
	}

	public function padUrl(): string {
		return $this->padUrl;
	}

	public function publicOpenUrl(): string {
		return $this->publicOpenUrl;
	}
}
