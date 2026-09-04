<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A `.pad` file holding something this app cannot make sense of: no
 * frontmatter, and not a legacy shortcut either.
 *
 * The opposite of `MissingFrontmatterException`, which says a file can
 * probably be initialised — this one says it cannot. Sharing a type made
 * the initialize endpoint answer its own refusal with the code that asks
 * a client to call it.
 */
class UnrecognisedPadContentException extends PadFileFormatException {
}
