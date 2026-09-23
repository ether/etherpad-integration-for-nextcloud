<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/** A sweep's next Etherpad call would not finish inside its run, so it is not made. */
class RunBudgetSpentException extends \RuntimeException {
}
