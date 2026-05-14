<?php

declare(strict_types=1);

namespace OCP\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;

if (!class_exists(TimedJob::class)) {
	abstract class TimedJob {
		private int $interval = 0;

		public function __construct(protected ITimeFactory $time) {
		}

		public function setInterval(int $interval): void {
			$this->interval = $interval;
		}

		public function getInterval(): int {
			return $this->interval;
		}

		/**
		 * @param mixed $argument
		 */
		abstract protected function run($argument): void;
	}
}
