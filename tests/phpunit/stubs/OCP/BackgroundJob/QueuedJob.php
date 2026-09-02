<?php

declare(strict_types=1);

namespace OCP\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;

if (!class_exists(QueuedJob::class)) {
	/**
	 * Mirrors the production lifecycle in the one respect that matters to
	 * anything written against it: `start()` removes the job from the list
	 * **before** running it. A job that wants another pass has to say so
	 * itself, because by the time it runs, its row is already gone.
	 *
	 * A stub that only exposed `run()` would let a job be written as if a
	 * failed run could simply be left to the queue.
	 */
	abstract class QueuedJob implements IJob {
		public function __construct(protected ITimeFactory $time) {
		}

		final public function start(IJobList $jobList): void {
			$jobList->remove($this, $this->argument);
			$this->run($this->argument);
		}

		/** @var mixed */
		protected $argument = null;

		/**
		 * @param mixed $argument
		 */
		public function setArgument($argument): void {
			$this->argument = $argument;
		}

		/**
		 * @param mixed $argument
		 */
		abstract protected function run($argument);
	}
}
