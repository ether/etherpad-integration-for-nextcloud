<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * This instance has no address for Etherpad's API or no API key saved:
 * thrown before a call is sent, so nothing was made in Etherpad. It
 * counts as Etherpad not answering, as a rejected key does: every call
 * fails alike until an admin saves the settings, and the answer says
 * what it says for an outage. Told apart for its own log line, and so a
 * create from Files' picker removes the file Nextcloud made for it,
 * while that is unchanged.
 */
class EtherpadNotConfiguredException extends EtherpadClientException {
}
