<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * What became of a waiting binding when a sweep came to it: as much as
 * the sweep needs to count its work and to tell an outage from the rest.
 */
enum SettleOutcome {
	/** Taken back, replaced, or deleted along with its file. */
	case Settled;

	/** Etherpad gave no answer, or the file could not be read; the row waits on. */
	case Unanswered;

	/**
	 * Left as it was, for a reason that is no outage: another flow has the
	 * row, the setting keeps the pad, the file is not where it should be.
	 */
	case Left;
}
