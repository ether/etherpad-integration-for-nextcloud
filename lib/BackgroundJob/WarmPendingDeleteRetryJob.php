<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

class WarmPendingDeleteRetryJob extends AbstractPendingDeleteRetryJob {
	protected const INTERVAL_SECONDS = 60 * 60;
	protected const MIN_AGE_SECONDS = 60 * 60;
	protected const MAX_AGE_SECONDS = 24 * 60 * 60;
}
