<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * The file's row names a pad Etherpad has lost (ManagedPadLifecycle::
 * howLost()): the file's own content is all that is left of it, and a new
 * pad can be made from it (RestoreService::recoverFromSnapshot()).
 */
final class PadLostException extends BindingException {
}
