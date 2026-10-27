<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A legacy Ownpad file naming a pad it cannot be linked to: an id
 * Etherpad's API refuses or a `.pad` file cannot hold, or a group pad its
 * group does not have. A format problem to everything that catches those,
 * with a sentence of its own for the reader, since the file itself is
 * well-formed.
 */
final class LegacyPadNotFoundException extends PadFileFormatException {
}
