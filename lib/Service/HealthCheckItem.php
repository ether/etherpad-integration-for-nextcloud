<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * One line of the connection test, so the admin sees which part is wrong
 * rather than a single combined verdict.
 */
class HealthCheckItem {
	public const STATUS_OK = 'ok';
	public const STATUS_WARNING = 'warning';
	public const STATUS_SKIPPED = 'skipped';

	public function __construct(
		public readonly string $id,
		public readonly string $status,
		/** Already translated: these are built for display. */
		public readonly string $label,
		public readonly string $detail = '',
	) {
	}
}
