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
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * Paces the timed syncs of a file. A viewer's timer sends one every
 * interval, so V viewers of a pad would each read the file and ask
 * Etherpad, and while someone types each would write it, a file version
 * each time. A timed sync runs only where it claims the file for half the
 * interval, rounded up (claim()); any other sync that looked at the file -
 * forced, or a call of the API - holds the timed ones off for as long
 * (note()). A claim stands for a try, not for a look that went through,
 * and runs out on its own: one that failed holds the timed syncs off too,
 * which spares an Etherpad, or a server of a pad elsewhere, that does not
 * answer. So a user whose timed syncs keep failing for that user alone -
 * a lock someone else holds through files_lock, a key that cannot be
 * read - can hold every other user's off whose timer comes within half an
 * interval after theirs, round after round. Etherpad keeps the content;
 * the file's copy, and backups of it, stay behind until a forced sync - a
 * tab hidden or left - which a browser closed hard does not send.
 *
 * The window bounds the timed syncs that start, not those that run: one
 * that outlasts it, or waits for the sync lock past it, may meet the
 * next, and with `filelocking.enabled` false they overlap. One that
 * claimed the file while another sync held the lock waits for it, and
 * then looks at what that one may just have looked at.
 *
 * The interval is part of the key, so one the admin changes counts at
 * once. Kept in the distributed memory cache, which counts the time - APCu
 * in whole seconds, up to a second longer. Where each web server keeps
 * its own, only the syncs one server takes are paced; see
 * https://docs.nextcloud.com/server/stable/admin_manual/configuration_server/caching_configuration.html.
 * Without a memory cache that can claim a key (IMemcache), or with one
 * that fails, every sync runs.
 */
class RecentSyncs {
	private ?IMemcache $cache = null;
	private bool $opened = false;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private AppConfigService $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether a timed sync of the file may run: where it claimed the file,
	 * or where the cache fails to say.
	 */
	public function claim(int $fileId): bool {
		try {
			$cache = $this->cache();
			if ($cache === null) {
				return true;
			}
			[$key, $seconds] = $this->window($fileId);
			// Read first: with several viewers, most timed syncs find a claim,
			// at one round trip.
			// A cache can answer false for a failure too, to add() and get()
			// alike (Nextcloud's Memcached does), so only a claim read back
			// keeps the sync off: the number 1, as APCu, Memcached and Redis
			// all hand it back.
			return $cache->get($key) !== 1
				&& ($cache->add($key, 1, $seconds) || $cache->get($key) !== 1);
		} catch (\Throwable $e) {
			$this->cacheFailed($e);
			return true;
		}
	}

	/** A look at the file by a sync that is not timed. */
	public function note(int $fileId): void {
		try {
			[$key, $seconds] = $this->window($fileId);
			$this->cache()?->set($key, 1, $seconds);
		} catch (\Throwable $e) {
			$this->cacheFailed($e);
		}
	}

	/** @return array{string, int} the key, and half the interval, rounded up */
	private function window(int $fileId): array {
		$interval = $this->appConfig->getSyncIntervalSeconds();
		return [$fileId . '/' . $interval, intdiv($interval + 1, 2)];
	}

	private function cache(): ?IMemcache {
		if (!$this->opened) {
			$this->opened = true;
			$cache = $this->cacheFactory->isAvailable()
				? $this->cacheFactory->createDistributed(Application::APP_ID . '/recent-syncs/')
				: null;
			// add() is IMemcache's; createDistributed() promises only an ICache.
			$this->cache = $cache instanceof IMemcache ? $cache : null;
		}
		return $this->cache;
	}

	private function cacheFailed(\Throwable $e): void {
		$this->logger->debug('The memory cache failed, so the timed syncs of a pad run unpaced while it fails.', [
			'app' => Application::APP_ID,
			...SafeError::context($e),
		]);
	}
}
