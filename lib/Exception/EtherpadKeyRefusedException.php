<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * This instance's Etherpad refused this Nextcloud's API key: HTTP 401 or
 * 403, or code 4 in its answer. The key is wrong, or Etherpad wants its API
 * calls signed another way (an authenticationMethod other than apikey). It
 * counts as Etherpad not answering, not as a refusal: every call fails
 * alike until an admin fixes the key, so the jobs try again on their next
 * run and an answer says to try again, where a refusal would set each
 * pad aside as a case of its own. ApiErrorLog names it.
 */
class EtherpadKeyRefusedException extends EtherpadClientException {
}
