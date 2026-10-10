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
use OCP\Security\ICrypto;

/**
 * The memory cache a public link's state is kept in, and the keys it is
 * kept under: an HMAC under the instance's secret, so a key does not give
 * away the share token, even one someone chose and could be guessed.
 */
class PublicLinkCache {
	public function __construct(
		private ICacheFactory $cacheFactory,
		private ICrypto $crypto,
	) {
	}

	/**
	 * The distributed cache under $prefix, or null without a memory cache.
	 *
	 * @throws \Throwable as a cache that fails can while it is set up
	 */
	public function open(string $prefix): ?ICache {
		return $this->cacheFactory->isAvailable()
			? $this->cacheFactory->createDistributed(Application::APP_ID . '/' . $prefix . '/')
			: null;
	}

	/** A key for $parts that does not carry them. */
	public function key(string ...$parts): string {
		return bin2hex($this->crypto->calculateHMAC(implode("\n", $parts)));
	}
}
