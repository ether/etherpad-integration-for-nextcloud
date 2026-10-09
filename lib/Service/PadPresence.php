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
	 * Etherpad has a pad under that id without a single revision, while the
	 * file holds saved content and other text than it: a pad made anew in
	 * place of the file's, on a visit or through the API
	 * (ManagedPadLifecycle::isMadeAnew()). A pad merely behind the
	 * snapshot - 3 revisions against 5 - is not this. Left where it is:
	 * whoever made it may want what it holds.
	 */
	case Behind;
}
