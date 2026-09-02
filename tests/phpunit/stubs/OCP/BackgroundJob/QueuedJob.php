<?php

declare(strict_types=1);

namespace OCP\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;

if (!class_exists(QueuedJob::class)) {
	abstract class QueuedJob implements IJob {
		public function __construct(protected ITimeFactory $time) {
		}

		/**
		 * The production base class runs the job once and removes it from
		 * the list. Only the argument handling is under test here, so this
		 * exposes `run` and nothing else.
		 *
		 * @param mixed $argument
		 */
		public function callRun($argument): void {
			$this->run($argument);
		}

		/**
		 * @param mixed $argument
		 */
		abstract protected function run($argument);
	}
}
