<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The files a sync wrote within the last half sync interval. Every viewer
 * of a pad syncs on its own timer, so while someone types, each of V
 * viewers would find a new revision and write it: V writes and V file
 * versions an interval. A viewer's timed sync leaves a file written this
 * recently be (PadSyncService), so it is written at most about twice an
 * interval, however many have it open; any other sync - a viewer leaving,
 * an embedding host asking, a call of the API - runs as before.
 *
 * Kept in the distributed memory cache: where that is each web server's
 * own, only the syncs one server takes are paced, and APCu, counting in
 * whole seconds, keeps a note up to a second longer. Without a memory
 * cache, or with one that fails, nothing is noted and every sync runs.
 */
class RecentSyncWrites {
	private ?ICache $cache = null;
	private bool $opened = false;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private AppConfigService $appConfig,
	) {
	}

	public function has(int $fileId): bool {
		try {
			return $this->cache()?->get((string)$fileId) !== null;
		} catch (\Exception) {
			return false;
		}
	}

	public function note(int $fileId): void {
		try {
			$this->cache()?->set((string)$fileId, 1, intdiv($this->appConfig->getSyncIntervalSeconds(), 2));
		} catch (\Exception) {
		}
	}

	private function cache(): ?ICache {
		if (!$this->opened) {
			$this->opened = true;
			$this->cache = $this->cacheFactory->isAvailable()
				? $this->cacheFactory->createDistributed(Application::APP_ID . '/sync-writes/')
				: null;
		}
		return $this->cache;
	}
}
