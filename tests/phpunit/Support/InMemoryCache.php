<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCP\IMemcache;

/**
 * A memory cache that keeps what it is given, and for how long each key
 * was set to live. Nothing expires: a test that needs a key gone removes
 * it.
 */
final class InMemoryCache implements IMemcache {
	/** @var array<string,mixed> */
	public array $values = [];
	/** @var array<string,int> */
	public array $ttls = [];

	public function get(string $key): mixed {
		return $this->values[$key] ?? null;
	}

	public function set(string $key, mixed $value, int $ttl = 0): bool {
		$this->values[$key] = $value;
		$this->ttls[$key] = $ttl;
		return true;
	}

	public function add(string $key, mixed $value, int $ttl = 0): bool {
		return !array_key_exists($key, $this->values) && $this->set($key, $value, $ttl);
	}

	public function hasKey(string $key): bool {
		return array_key_exists($key, $this->values);
	}

	public function remove(string $key): bool {
		unset($this->values[$key], $this->ttls[$key]);
		return true;
	}

	public function clear(string $prefix = ''): bool {
		$this->values = [];
		$this->ttls = [];
		return true;
	}

	public function inc(string $key, int $step = 1): int|bool {
		$this->values[$key] = (int)($this->values[$key] ?? 0) + $step;
		return $this->values[$key];
	}

	public function dec(string $key, int $step = 1): int|bool {
		return $this->inc($key, -$step);
	}

	public function cas(string $key, mixed $old, mixed $new): bool {
		if (($this->values[$key] ?? null) !== $old) {
			return false;
		}
		$this->values[$key] = $new;
		return true;
	}

	public function cad(string $key, mixed $old): bool {
		if (($this->values[$key] ?? null) !== $old) {
			return false;
		}
		unset($this->values[$key], $this->ttls[$key]);
		return true;
	}
}
