<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\PendingBindingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Settles the pad bindings that wait - restores left undecided, and
 * deletions owed once their file is gone for good. Named for what these
 * jobs did first; the job list stores the class name, so the name stays.
 */
abstract class AbstractPendingDeleteRetryJob extends TimedJob {
	protected const INTERVAL_SECONDS = 24 * 60 * 60;
	protected const MIN_AGE_SECONDS = 0;
	protected const MAX_AGE_SECONDS = null;
	protected const LIMIT = 200;

	public function __construct(
		ITimeFactory $time,
		private PendingBindingService $pendingBindings,
	) {
		parent::__construct($time);
		$this->setInterval(static::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$this->pendingBindings->settleByAge(static::MIN_AGE_SECONDS, static::MAX_AGE_SECONDS, static::LIMIT);
	}
}
