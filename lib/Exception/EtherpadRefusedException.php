<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * This instance's Etherpad answered with an error code of its own, other
 * than a rejected key: for example a pad or group it does not have, or an
 * internal error. The server answered, so this is not an outage; what it
 * said is for the admin. Any other
 * EtherpadClientException of our own Etherpad is no proper answer, a key it
 * rejected (EtherpadKeyRejectedException), or a pad too large to read
 * (EtherpadTooLargeException).
 */
class EtherpadRefusedException extends EtherpadClientException {
	/** Etherpad answered. */
	public function meansEtherpadUnreachable(): bool {
		return false;
	}
}
