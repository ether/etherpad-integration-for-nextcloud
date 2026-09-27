<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * How Etherpad has lost a file's pad (ManagedPadLifecycle::howLost()): a
 * pad under the right id is not always the right pad.
 */
enum PadPresence {
	/** Etherpad answered that it does not exist. */
	case Absent;

	/**
	 * Etherpad has a pad under that id, with fewer revisions than the file's
	 * snapshot was taken at: not the pad the file knew. Neither to be taken
	 * back nor thrown away - whoever wrote into it has only that copy.
	 */
	case Behind;
}
