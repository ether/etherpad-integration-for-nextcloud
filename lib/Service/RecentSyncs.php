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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * The files a sync looked at within the last half sync interval, rounded
 * up and counted from the sync's start: compared with Etherpad, and
 * written where the pad had moved on. Every viewer of a pad syncs on its
 * own timer, so V viewers would each ask Etherpad every interval, and
 * while someone types each would write it: V file versions an interval. A
 * viewer's timed sync leaves a file looked at this recently be
 * (PadSyncService), so it is looked at, and written, at most about twice
 * an interval, however many have it open; any other sync - a viewer
 * leaving, an embedding host asking, a call of the API - always runs.
 * Counted from the start, a sync that took long does not leave its own
 * viewer's next one be. A note keeps when the look began and is measured
 * against the interval of the moment it is asked about, so an interval the
 * admin shortens holds for the notes already kept.
 *
 * Kept in the distributed memory cache: where that is each web server's
 * own, only the syncs one server takes are paced, and APCu, counting in
 * whole seconds, keeps a note up to a second longer. With
 * `filelocking.enabled` false the syncs of a file overlap (PadSyncLock),
 * so the timed syncs that start while a look runs all run. Without a
 * memory cache, or with one that fails, nothing is noted and every sync
 * runs.
 */
class RecentSyncs {
	private ?ICache $cache = null;
	private bool $opened = false;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private AppConfigService $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	public function has(int $fileId): bool {
		try {
			return $this->isRecent($this->cache()?->get((string)$fileId));
		} catch (\Throwable $e) {
			$this->cacheFailed($e);
			return false;
		}
	}

	/** A look at the file that began at $since. */
	public function note(int $fileId, int $since): void {
		try {
			$this->cache()?->set((string)$fileId, $since, max(1, $this->halfInterval() - ($this->timeFactory->getTime() - $since)));
		} catch (\Throwable $e) {
			$this->cacheFailed($e);
		}
	}

	/** Whether a note began within the half interval of now; a cache may hand a number back as a string. */
	private function isRecent(mixed $since): bool {
		return is_numeric($since) && $this->timeFactory->getTime() - (int)$since < $this->halfInterval();
	}

	/** Rounded up, so that even at the five second floor a note keeps the looks to under two an interval. */
	private function halfInterval(): int {
		return intdiv($this->appConfig->getSyncIntervalSeconds() + 1, 2);
	}

	private function cache(): ?ICache {
		if (!$this->opened) {
			$this->opened = true;
			$this->cache = $this->cacheFactory->isAvailable()
				? $this->cacheFactory->createDistributed(Application::APP_ID . '/recent-syncs/')
				: null;
		}
		return $this->cache;
	}

	private function cacheFailed(\Throwable $e): void {
		$this->logger->debug('The memory cache failed, so the timed syncs of a pad run as if none had looked at it while it fails.', [
			'app' => Application::APP_ID,
			...SafeError::context($e),
		]);
	}
}
