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

/** Every five minutes, a run of GoneFileSweep. */
class GoneFileSweepJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private GoneFileSweep $sweep,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$this->sweep->run();
	}
}
