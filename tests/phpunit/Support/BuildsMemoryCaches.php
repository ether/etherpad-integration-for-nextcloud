<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\Service\PublicLinkCache;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;

/**
 * The cache factories the tests of the app's memory caches build on, and
 * the PublicLinkCache a public link's tests use. The caches are each
 * test's own: what one keeps - values, times to live, counts - is what
 * that test is about.
 */
trait BuildsMemoryCaches {
	/** A factory that hands out $cache as the distributed cache. */
	private function cacheFactoryFor(ICache $cache): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		return $factory;
	}

	/** No memory cache: none may be built. */
	private function noMemoryCache(): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$factory->expects($this->never())->method('createDistributed');
		return $factory;
	}

	/** PublicLinkCache over $factory, its HMAC a plain SHA-256 of the message. */
	private function linkCache(ICacheFactory $factory): PublicLinkCache {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash('sha256', $message, true));
		return new PublicLinkCache($factory, $crypto);
	}
}
