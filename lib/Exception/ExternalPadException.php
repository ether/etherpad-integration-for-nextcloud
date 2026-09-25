<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A pad on another server that could not be linked or read: the link is
 * not one this instance allows, that server did not answer, or it has no
 * such pad. Not this instance's Etherpad failing, and nothing its admin
 * can mend; the message says what was wrong with the link.
 */
class ExternalPadException extends EtherpadClientException {
}
