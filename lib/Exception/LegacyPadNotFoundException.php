<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A legacy Ownpad file naming a group pad its Etherpad group does not
 * have: the migration refuses it. A format problem to everything that
 * catches those, with a sentence of its own for the reader, since the
 * file itself is well-formed.
 */
final class LegacyPadNotFoundException extends PadFileFormatException {
}
