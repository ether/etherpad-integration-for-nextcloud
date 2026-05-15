<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Result of an authenticated sync run. Common fields are always
 * populated; the optional ones depend on which `$status` branch fired:
 *
 * - STATUS_UPDATED for an external pad → `$snapshotRev` + `$lockRetries`
 * - STATUS_UPDATED for an internal pad → `$snapshotRev` + `$lockRetries`
 * - STATUS_UNCHANGED for an internal pad → `$snapshotRev` + `$currentRev`
 * - STATUS_UNCHANGED for an external pad → nothing extra
 * - STATUS_LOCKED → `$lockRetries` + `$retryable = true`
 */
class PadSyncResult {
	public function __construct(
		public readonly string $status,
		public readonly int $fileId,
		public readonly string $padId,
		public readonly bool $external,
		public readonly bool $forced,
		public readonly ?int $snapshotRev = null,
		public readonly ?int $currentRev = null,
		public readonly ?int $lockRetries = null,
		public readonly bool $retryable = false,
	) {
	}
}
