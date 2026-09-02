<?php

declare(strict_types=1);

namespace OCP\BackgroundJob;

if (!interface_exists(IJobList::class)) {
	interface IJobList {
		/**
		 * @param mixed $argument
		 */
		public function add(IJob|string $job, mixed $argument = null): void;

		/**
		 * @param mixed $argument
		 */
		public function scheduleAfter(string $job, int $runAfter, mixed $argument = null): void;

		/**
		 * @param mixed $argument
		 */
		public function has(IJob|string $job, mixed $argument): bool;

		/**
		 * @param mixed $argument
		 */
		public function remove(IJob|string $job, mixed $argument = null): void;
	}
}
