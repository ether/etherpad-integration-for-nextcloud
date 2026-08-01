<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

class StoredAdminSettings {
	public function __construct(
		public readonly string $apiKey,
		public readonly string $cookieDomain,
		public readonly bool $deleteOnTrash,
		public readonly bool $allowExternalPads,
		public readonly string $trustedEmbedOrigins,
		public readonly bool $enableProtectedPads = true,
		public readonly bool $enablePublicPads = true,
		/** False while no cookie domain was ever saved, so it may be derived. */
		public readonly bool $cookieDomainConfigured = false,
	) {
	}
}
