<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * The folder a path names to create a `.pad` in is not there, or is no
 * folder. Its own type rather than NotFoundException: an endpoint that
 * also reads a template answers that one's absence with another sentence.
 */
class PadParentFolderNotFoundException extends \RuntimeException {
}
