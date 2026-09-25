<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * The insert of a file's row failed: another request made one at the same
 * moment, or the database did not take it. Its cause is the database's
 * error; the pad it was for has been created by then.
 */
final class BindingNotCreatedException extends BindingException {
}
