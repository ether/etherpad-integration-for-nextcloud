<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCP\ICache;

/** A memory cache that keeps what it is given, and for how long each key was set to live. Nothing expires. */
final class InMemoryCache implements ICache {
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

	public function hasKey(string $key): bool {
		return isset($this->values[$key]);
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
}
