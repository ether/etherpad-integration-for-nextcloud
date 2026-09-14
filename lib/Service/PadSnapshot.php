<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Content a `.pad` starts out with, as one value.
 *
 * A document either carries a snapshot or does not. Passing the text, its
 * HTML half and the revision separately let a caller ask for half of one and
 * half of the other.
 */
class PadSnapshot {
	/**
	 * @param string $html the HTML half, empty where there is none - an
	 *                     external pad's HTML is deliberately never fetched.
	 *                     A stored snapshot writes the section either way.
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $html,
		public readonly int $revision,
	) {
		// -1 is how the format says "no snapshot yet"; a snapshot that exists
		// cannot be at that revision, and silently raising it to 0 would make
		// a never-synced pad look synced. This is a caller's mistake, so it is
		// not the exception that tells a user their .pad is malformed.
		if ($revision < 0) {
			throw new \InvalidArgumentException('A snapshot revision cannot be negative.');
		}
	}
}
