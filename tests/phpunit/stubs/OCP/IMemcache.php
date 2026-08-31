<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IMemcache::class)) {
	interface IMemcache extends ICache {
		/** Set a value only if the key is not taken. Atomic. */
		public function add(string $key, mixed $value, int $ttl = 0): bool;

		public function inc(string $key, int $step = 1): int|bool;

		public function dec(string $key, int $step = 1): int|bool;

		public function cas(string $key, mixed $old, mixed $new): bool;

		public function cad(string $key, mixed $old): bool;
	}
}
