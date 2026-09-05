<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;

/**
 * Content a `.pad` starts out with, as one value.
 *
 * A document either carries a snapshot or does not, and the two cases write
 * different bodies. Passing the text, its HTML half and the revision
 * separately let a caller ask for half of one and half of the other.
 */
class PadSnapshot {
	/**
	 * @param ?string $html the HTML half, or null for a text-only snapshot —
	 *                      which is not the same as an empty HTML half
	 * @throws PadFileFormatException
	 */
	public function __construct(
		public readonly string $text,
		public readonly ?string $html,
		public readonly int $revision,
	) {
		// -1 is how the format says "no snapshot yet"; a snapshot that exists
		// cannot be at that revision, and silently raising it to 0 would make
		// a never-synced pad look synced.
		if ($revision < 0) {
			throw new PadFileFormatException('A snapshot revision cannot be negative.');
		}
	}
}
