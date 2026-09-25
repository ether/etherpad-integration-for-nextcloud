<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A file's row names another pad, or another access mode, than the file:
 * data an admin has to mend, and the one binding error worth a warning.
 */
final class BindingMismatchException extends BindingException {
}
