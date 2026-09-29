<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/** The user may read the .pad file but not change it, and what was asked would change it. */
class PadFileNotWritableException extends \RuntimeException {
}
