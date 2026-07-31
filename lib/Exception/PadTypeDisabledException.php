<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * Raised when a pad of a given access mode is requested but the admin has
 * switched that pad type off. Existing pads of the type keep working — this
 * only guards creation.
 */
class PadTypeDisabledException extends \RuntimeException {
}
