<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * What Etherpad could say about a pad. Three answers, not two: a pad the
 * server could not be asked about is not a pad that is gone, and a
 * decision that treats the two alike either keeps what should go or
 * deletes what should stay.
 */
enum PadPresence {
	/** Etherpad answered for it. */
	case Present;

	/** Etherpad answered that it does not exist. */
	case Absent;

	/** No answer: a transport failure, a timeout, anything but a reply. */
	case Unknown;
}
