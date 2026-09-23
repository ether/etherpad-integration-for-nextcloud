<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

class HotPendingDeleteRetryJob extends AbstractPendingDeleteRetryJob {
	protected const INTERVAL_SECONDS = 5 * 60;
	protected const MIN_AGE_SECONDS = 0;
	protected const MAX_AGE_SECONDS = 60 * 60;
}
