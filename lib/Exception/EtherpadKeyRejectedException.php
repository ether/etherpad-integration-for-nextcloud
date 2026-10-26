<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * This instance's Etherpad rejected this Nextcloud's API key: it answered
 * with its own code 4, under HTTP 401 as Etherpad sends it - its API gives
 * a 403 code 4 too - or under a success status. The key is wrong, or
 * Etherpad wants its API calls signed another way (an authenticationMethod
 * other than apikey). A 401 or 403 without that answer is not this: a
 * proxy or firewall in front of Etherpad sent it.
 *
 * It counts as Etherpad not answering, not as a refusal: every call fails
 * alike until an admin fixes the key, so the jobs try again on their next
 * run and an answer says to try again, where a refusal would set each pad
 * aside as a case of its own. ApiErrorLog names it, for an answer and
 * wherever a job or listener meets it.
 */
class EtherpadKeyRejectedException extends EtherpadClientException {
}
