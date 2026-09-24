<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * What became of a waiting binding when a sweep, or an open, came to it:
 * as much as the sweep needs to count its work and to tell an outage from
 * the rest.
 */
enum SettleOutcome {
	/** Taken back, released for the file to recover itself, or deleted along with its file. */
	case Settled;

	/** Etherpad gave no answer; the row waits on. */
	case Unanswered;

	/**
	 * Left as it was, for a reason that is no outage: the file's own
	 * trouble, another flow holding the row, the setting, or the run's
	 * budget.
	 */
	case Left;
}
