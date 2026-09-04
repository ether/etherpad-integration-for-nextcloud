<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * What one create attempt knows about the file it claimed.
 *
 * Held together on purpose. Each of these was being re-derived at the point
 * of use — the id read again from a node, the prior content read again
 * inside a helper, the written hash returned through a value that a failing
 * caller never received — and every one of those re-derivations was a way
 * to act on the wrong file or with the wrong expectation.
 *
 * Carried by reference, so a write that succeeds reaches the rollback of a
 * step that fails afterwards.
 */
class CreatedFileClaim {
	public ?string $writtenHash = null;

	public function __construct(
		public readonly string $uid,
		public readonly int $fileId,
		/** What the file held when this attempt claimed it: empty for a file
		 *  we created, the template's bytes for Nextcloud's template hook. */
		public readonly string $expectedBefore = '',
	) {
	}
}
