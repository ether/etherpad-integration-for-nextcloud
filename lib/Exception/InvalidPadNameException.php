<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * The instance will not accept this file name, and it said why.
 *
 * Its own message reaches the user: "Invalid pad name" tells someone that
 * something is wrong, while "\"COM1\" is a reserved name" tells them what to
 * change. Nextcloud produces that sentence and translates it; throwing it
 * away and replacing it with a generic one is a loss.
 */
class InvalidPadNameException extends \InvalidArgumentException {
}
