<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\PadFileLockRetryExhaustedException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * One sync of a file at a time, held from reading the file to writing it.
 * Two syncs that overlap ask Etherpad and write in no set order, and the
 * slower one can put the older revision over the newer: Nextcloud's file
 * lock covers one read or one write, not the two together. A second sync
 * waits for the first and reads what it wrote.
 *
 * Held through Nextcloud's locking provider, as creates are
 * (PadFileCreator). With `filelocking.enabled` false it holds nothing, and
 * syncs overlap. A request killed while it holds the lock leaves the
 * file's syncs answering `locked` until the provider lets the lock expire
 * (`filelocking.ttl`).
 */
class PadSyncLock {
	/** In microseconds, about three seconds in all: a sync usually takes less. */
	private const WAIT_DELAYS_US = [100000, 200000, 400000, 800000, 1600000];

	/** @var \Closure(int): void */
	private \Closure $sleeper;

	/** @param (\Closure(int): void)|null $sleeper */
	public function __construct(
		private ILockingProvider $lockingProvider,
		?\Closure $sleeper = null,
	) {
		$this->sleeper = $sleeper ?? static function (int $delay): void {
			\usleep($delay);
		};
	}

	/**
	 * @template T
	 * @param \Closure(): T $sync
	 * @return T
	 * @throws PadFileLockRetryExhaustedException when another sync of the file held it through every wait
	 */
	public function hold(int $fileId, \Closure $sync): mixed {
		$key = Application::APP_ID . ':sync:' . $fileId;
		$this->acquire($key);
		try {
			return $sync();
		} finally {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function acquire(string $key): void {
		$waits = 0;
		foreach (self::WAIT_DELAYS_US as $delay) {
			try {
				$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				return;
			} catch (LockedException) {
				($this->sleeper)($delay);
				$waits++;
			}
		}
		try {
			$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException $e) {
			throw new PadFileLockRetryExhaustedException($waits, $e);
		}
	}
}
