<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A pad whose export is past the size this app will read.
 *
 * Its own type so the read-only view can say "too large to show here"
 * rather than reporting it as a failed request — the pad is fine, and the
 * editable path is unaffected.
 */
class EtherpadTooLargeException extends EtherpadClientException {
}
