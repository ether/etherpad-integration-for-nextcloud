<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * This instance has no Etherpad address or no API key saved, so no call
 * to it can be made. It counts as Etherpad not answering, as a rejected
 * key does: every call fails alike until an admin saves the settings,
 * and the answer says what it says for an outage. Told apart for its own
 * log line, and so a create removes the file it could not finish.
 */
class EtherpadNotConfiguredException extends EtherpadClientException {
}
