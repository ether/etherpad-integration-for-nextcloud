<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(ICache::class)) {
	interface ICache {
		public function get(string $key): mixed;

		public function set(string $key, mixed $value, int $ttl = 0): bool;

		public function hasKey(string $key): bool;

		public function remove(string $key): bool;

		public function clear(string $prefix = ''): bool;

		/** Set a value only if the key is not taken. Atomic where the backend allows it. */
		public function add(string $key, mixed $value, int $ttl = 0): bool;
	}
}
