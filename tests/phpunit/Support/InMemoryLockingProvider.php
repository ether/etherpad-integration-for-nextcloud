<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Exclusive locks held in memory, shared by whoever holds the instance: two
 * services given the same one behave like two requests on one server. A
 * mock can only say a lock is taken; this one is taken because someone
 * took it, and free again once they let go.
 */
final class InMemoryLockingProvider implements ILockingProvider {
	/** @var array<string,true> */
	private array $held = [];

	public function isLocked(string $path, int $type): bool {
		return isset($this->held[$path]);
	}

	public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
		if (isset($this->held[$path])) {
			throw new LockedException($path);
		}
		$this->held[$path] = true;
	}

	public function releaseLock(string $path, int $type): void {
		unset($this->held[$path]);
	}

	public function changeLock(string $path, int $targetType): void {
	}

	public function releaseAll(): void {
		$this->held = [];
	}
}
