<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * Another sync wrote a snapshot at least as new into the file while this
 * one fetched its own (PadSyncService), which then writes nothing.
 */
class PadSnapshotOvertakenException extends \RuntimeException {
	public function __construct(
		public readonly int $snapshotRev,
	) {
		parent::__construct('Another sync wrote a snapshot at least as new.');
	}
}
