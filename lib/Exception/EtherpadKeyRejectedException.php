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
 * other than apikey). A 401 or 403 without that answer says nothing of the
 * key - a proxy or firewall in front of Etherpad may send one - and stays
 * an HTTP error.
 *
 * It counts as Etherpad not answering, not as a refusal: every call fails
 * alike until an admin fixes the key, so an answer says to try again and
 * the sweep ends its run, where a refusal would set each pad aside for an
 * hour as a case of its own. ApiErrorLog names it (report(),
 * reportIfKeyRejected()).
 *
 * @see https://docs.etherpad.org/api/http_api.html#response-format
 */
class EtherpadKeyRejectedException extends EtherpadClientException {
}
