<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\GoneFileSweep;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * A run of GoneFileSweep on every tick of a five-minute cron. Declared in
 * appinfo/info.xml, which Nextcloud registers on install and upgrade.
 *
 * The interval is just under the five minutes: Nextcloud runs a job only
 * once more than its interval has passed since it last started, and it
 * starts a moment after the tick, so five minutes would run it on every
 * other tick.
 *
 * @psalm-api
 */
class GoneFileSweepJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private GoneFileSweep $sweep,
	) {
		parent::__construct($time);
		$this->setInterval(4 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$this->sweep->run();
	}
}
