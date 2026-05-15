<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Result of the lightweight sync-status check used by the viewer's
 * polling loop. The unavailable / external branches set `$inSync = null`
 * and supply `$reason`; the live branches set `$inSync = bool` and the
 * `$snapshotRev` / `$currentRev` pair instead.
 */
class PadSyncStatus {
	public function __construct(
		public readonly string $status,
		public readonly ?bool $inSync = null,
		public readonly ?int $snapshotRev = null,
		public readonly ?int $currentRev = null,
		public readonly ?string $reason = null,
	) {
	}
}
