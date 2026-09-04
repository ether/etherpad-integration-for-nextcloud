<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * The target content differs from the create's expected state.
 * Recovery must not blank or delete that content.
 */
class PadFileChangedException extends \RuntimeException {
}
