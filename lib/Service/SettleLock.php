<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * The lock a waiting row is settled under, one per file:
 * `etherpad_nextcloud:settle:<fileId>`. Whoever decides a row holds it, so
 * two never decide the same row at once, and whoever finds it held leaves
 * the row to the holder: it is only ever tried, never waited for.
 *
 * Nextcloud's lock, so not re-entrant, and a process that dies holding it
 * leaves it until it expires (`filelocking.ttl`). With file locking
 * switched off (`filelocking.enabled`) Nextcloud hands out no locks, and
 * this one holds nothing either.
 */
class SettleLock {
	public function __construct(
		private ILockingProvider $locks,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * $settle while this file's row is held, or $busy when another holds it,
	 * without $settle running. A lock that cannot be taken for another
	 * reason - the database, say - is the caller's to place. One that cannot
	 * be let go is logged, and held until it expires.
	 *
	 * @template T
	 * @param \Closure(): T $settle
	 * @param \Closure(): T $busy
	 * @return T
	 */
	public function holding(int $fileId, \Closure $settle, \Closure $busy): mixed {
		$lock = Application::APP_ID . ':settle:' . $fileId;
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return $busy();
		}
		try {
			return $settle();
		} finally {
			try {
				$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not release the lock on a pad binding. It waits until the lock expires.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					...SafeError::context($e),
				]);
			}
		}
	}
}
