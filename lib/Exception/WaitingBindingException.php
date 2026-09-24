<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * The file's row waits - a deletion owed, or a restore Etherpad could not
 * answer for - so which pad is the file's is not settled yet. The sweep
 * settles it, or an open that tries first (SettleOnOpen); until then the
 * file does not open on a pad.
 */
final class WaitingBindingException extends BindingException {
}
