<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * The target file is no longer what this create claimed.
 *
 * Its own type because the only correct response is to stop touching the
 * file. A caller that treats it as a generic failure and "recovers" by
 * emptying the target destroys exactly the content this check found.
 */
class PadFileChangedException extends \RuntimeException {
}
