<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Identity, expected content and write evidence for one create attempt.
 *
 * Pass the same instance through writing and recovery so a later failure
 * retains the write evidence. A claim does not lock the file.
 */
class CreatedFileClaim {
	public ?string $writtenHash = null;

	public function __construct(
		public readonly string $uid,
		public readonly int $fileId,
		/** Initially empty for API creates; copied template content for the hook. */
		public readonly string $expectedBefore = '',
	) {
	}
}
