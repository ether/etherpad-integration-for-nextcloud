<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * What one question to Etherpad found about a pad: its presence, and the
 * revision count that answer came from, for a caller that has more to do
 * with it than decide. Null wherever Etherpad gave no count.
 */
final class PadProbe {
	public function __construct(
		public readonly PadPresence $presence,
		public readonly ?int $revisions,
	) {
	}
}
