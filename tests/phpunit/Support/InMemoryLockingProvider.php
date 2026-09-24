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
 * Locks held in memory, shared by whoever holds the instance: two services
 * given the same one behave like two requests on one server. A mock can
 * only say a lock is taken; this one is taken because someone took it, and
 * free again once they let go. Shared locks sit side by side, an exclusive
 * one alone, and a release lets go only of a lock of its own kind, as in
 * Nextcloud's providers - so a lock taken or let go shared where it should
 * be exclusive shows.
 */
final class InMemoryLockingProvider implements ILockingProvider {
	/** @var array<string,int> how many hold each path shared, or -1 for one holding it exclusively */
	private array $held = [];

	public function isLocked(string $path, int $type): bool {
		$held = $this->held[$path] ?? 0;
		return $type === self::LOCK_EXCLUSIVE ? $held === -1 : $held > 0;
	}

	public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
		$held = $this->held[$path] ?? 0;
		if ($type === self::LOCK_EXCLUSIVE ? $held !== 0 : $held === -1) {
			throw new LockedException($path);
		}
		$this->held[$path] = $type === self::LOCK_EXCLUSIVE ? -1 : $held + 1;
	}

	public function releaseLock(string $path, int $type): void {
		$held = $this->held[$path] ?? 0;
		if ($type === self::LOCK_EXCLUSIVE ? $held !== -1 : $held <= 0) {
			return;
		}
		if ($held === -1 || $held === 1) {
			unset($this->held[$path]);
		} else {
			$this->held[$path] = $held - 1;
		}
	}

	/** Not needed by the locks this app takes. */
	public function changeLock(string $path, int $targetType): void {
	}

	public function releaseAll(): void {
		$this->held = [];
	}
}
