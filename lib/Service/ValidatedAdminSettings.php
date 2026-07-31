<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

class ValidatedAdminSettings {
	public function __construct(
		public readonly string $etherpadHost,
		public readonly string $etherpadApiHost,
		public readonly string $etherpadCookieDomain,
		public readonly ?string $etherpadApiKey,
		public readonly string $effectiveApiKey,
		public readonly string $etherpadApiVersion,
		public readonly int $syncIntervalSeconds,
		public readonly bool $deleteOnTrash,
		public readonly bool $allowExternalPads,
		public readonly string $externalPadAllowlist,
		public readonly string $trustedEmbedOrigins,
		public readonly bool $enableProtectedPads = true,
		public readonly bool $enablePublicPads = true,
	) {
	}
}
