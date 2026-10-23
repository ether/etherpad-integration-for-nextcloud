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
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * One sync of a file at a time, held from reading the file to writing it.
 * Two syncs that overlap ask Etherpad and write in no set order, and the
 * slower one can put the older revision over the newer: Nextcloud's file
 * lock covers one read or one write, not the two together. A second sync
 * waits for the first and reads what it wrote; one that would rather not
 * wait - a viewer's timed sync, which the first one's look makes needless
 * (RecentSyncs) - takes the lock only if it is free, so a busy pad holds
 * no web server worker waiting.
 *
 * Held through Nextcloud's locking provider, as creates are
 * (PadFileCreator). With `filelocking.enabled` false it holds nothing, and
 * syncs overlap. Nextcloud lets go of it at the end of a request, after a
 * fatal error too; a request killed hard leaves the file's syncs answering
 * `locked` until the provider drops the lock: Redis once
 * `filelocking.ttl` has passed, the database at a background cleanup
 * after that, APCu after a day whatever `filelocking.ttl` says, Memcached
 * not by itself.
 */
class PadSyncLock {
	/**
	 * In microseconds: asked again after 75 to 125 ms each time, the jitter
	 * keeping waiters out of step, until the waits together would pass
	 * three seconds - a sync usually takes less.
	 */
	private const POLL_US = 75000;
	private const JITTER_US = 50000;
	private const WAIT_US = 3000000;

	/** @var \Closure(int): void */
	private \Closure $sleeper;

	/** @param (\Closure(int): void)|null $sleeper */
	public function __construct(
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
		?\Closure $sleeper = null,
	) {
		$this->sleeper = $sleeper ?? static function (int $delay): void {
			\usleep($delay);
		};
	}

	/**
	 * @template T
	 * @param \Closure(): T $sync
	 * @param bool $wait false to take the lock only if it is free, without a wait
	 * @return T
	 * @throws PadFileLockRetryExhaustedException when the file's sync lock stayed taken through every wait, or was taken where there was to be none: by another sync of it, or left by a request killed hard
	 */
	public function hold(int $fileId, \Closure $sync, bool $wait = true): mixed {
		$key = Application::APP_ID . ':sync:' . $fileId;
		$this->acquire($key, $wait);
		try {
			return $sync();
		} finally {
			$this->release($key);
		}
	}

	private function acquire(string $key, bool $wait): void {
		$waits = 0;
		$waited = 0;
		while (true) {
			try {
				$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				return;
			} catch (LockedException $e) {
				$delay = self::POLL_US + random_int(0, self::JITTER_US);
				if (!$wait || $waited + $delay > self::WAIT_US) {
					throw new PadFileLockRetryExhaustedException($waits, $e);
				}
				($this->sleeper)($delay);
				$waited += $delay;
				$waits++;
			}
		}
	}

	/**
	 * A lock that cannot be let go hides neither what the sync threw nor
	 * that it went through. It may then stay as after a request killed hard
	 * (see the class).
	 */
	private function release(string $key): void {
		try {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable $e) {
			$this->logger->warning('The sync lock of a .pad file could not be let go', [
				'app' => Application::APP_ID,
				'lock' => $key,
				...SafeError::context($e),
			]);
		}
	}
}
