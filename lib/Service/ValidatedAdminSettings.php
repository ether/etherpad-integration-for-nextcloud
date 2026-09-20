<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\ApiKey;

class ValidatedAdminSettings {
	public function __construct(
		public readonly string $etherpadHost,
		public readonly string $etherpadApiHost,
		public readonly string $etherpadCookieDomain,
		/**
		 * Private, and reached through the getters below: a public property
		 * is what get_object_vars() hands a serialized stack trace, and this
		 * object is a frame argument for the whole of a connection test.
		 */
		private readonly ?string $etherpadApiKey,
		private readonly string $effectiveApiKey,
		public readonly string $etherpadApiVersion,
		public readonly int $syncIntervalSeconds,
		public readonly bool $deleteOnTrash,
		public readonly bool $allowExternalPads,
		public readonly string $externalPadAllowlist,
		public readonly string $trustedEmbedOrigins,
		public readonly bool $enableProtectedPads = true,
		public readonly bool $enablePublicPads = true,
		public readonly bool $cookieDomainConfigured = false,
		public readonly bool $allowLegacyProtectedImport = false,
	) {
	}

	/** The key to persist, or null when the stored one is being kept. */
	public function apiKeyToStore(): ?ApiKey {
		return $this->etherpadApiKey === null ? null : new ApiKey($this->etherpadApiKey);
	}

	/** The key these settings would talk to Etherpad with. */
	public function effectiveApiKey(): ApiKey {
		return new ApiKey($this->effectiveApiKey);
	}
}
