<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * What Etherpad could say about a pad. More answers than there or not: a
 * pad the server could not be asked about is not a pad that is gone, and
 * a pad under the right id is not always the right pad. A decision that
 * treats any two of these alike either keeps what should go or deletes
 * what should stay.
 */
enum PadPresence {
	/** Etherpad answered for it. */
	case Present;

	/** Etherpad answered that it does not exist. */
	case Absent;

	/**
	 * Etherpad has a pad under that id, with fewer revisions than the file's
	 * snapshot was taken at: not the pad the file knew. Neither to be taken
	 * back nor thrown away - whoever wrote into it has only that copy.
	 */
	case Behind;

	/** No answer: a transport failure, a timeout, anything but a reply. */
	case Unknown;
}
