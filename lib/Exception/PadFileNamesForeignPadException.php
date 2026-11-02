<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A file with a row whose text names a pad on another server. Trying again
 * does not help: the row says which pad the file has.
 */
final class PadFileNamesForeignPadException extends BindingException {
}
