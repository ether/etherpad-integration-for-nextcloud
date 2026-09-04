<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A pad whose export is past the size this app will read. Its own type so
 * the view can say "too large to show here" rather than "request failed" —
 * the pad is fine, and stays editable.
 */
class EtherpadTooLargeException extends EtherpadClientException {
}
