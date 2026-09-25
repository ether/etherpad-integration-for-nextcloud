<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * This instance's Etherpad answered, and refused: a pad or group it does
 * not have, a key it does not take. Trying again gives the same answer;
 * what it said is for the admin. Any other EtherpadClientException of our
 * own Etherpad is one it did not answer properly.
 */
class EtherpadRefusedException extends EtherpadClientException {
	/** Etherpad answered. */
	public function meansEtherpadUnreachable(): bool {
		return false;
	}
}
