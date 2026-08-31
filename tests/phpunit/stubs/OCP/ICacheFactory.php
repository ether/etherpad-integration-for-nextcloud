<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(ICacheFactory::class)) {
	interface ICacheFactory {
		/** Whether a memory cache is configured at all. */
		public function isAvailable(): bool;

		public function createDistributed(string $prefix = ''): ICache;

		public function createLocal(string $prefix = ''): ICache;
	}
}
