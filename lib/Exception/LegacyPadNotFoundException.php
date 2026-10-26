<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A legacy Ownpad file naming a pad it cannot be bound to: a group pad its
 * Etherpad group does not have, or an id Etherpad's API refuses
 * (PadId::isValid()). The migration refuses it. A format problem to everything that catches those, with a sentence
 * of its own for the reader, since the file itself is well-formed.
 */
final class LegacyPadNotFoundException extends PadFileFormatException {
}
