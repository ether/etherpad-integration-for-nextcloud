<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

class HealthCheckResult {
	public function __construct(
		public readonly string $host,
		public readonly string $apiHost,
		public readonly string $apiVersion,
		public readonly int $padCount,
		public readonly int $latencyMs,
		public readonly string $target,
		public readonly int $pendingDeleteCount,
		/** null when protected pads are switched off, so nothing was checked. */
		public readonly ?CookieDomainDecision $cookieDomain = null,
		public readonly string $cookieDomainMessage = '',
	) {
	}
}
