<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\RecentSyncs;
use OCA\EtherpadNextcloud\Tests\Support\BuildsLinkCaches;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryCache;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which files a sync looked at within the last half interval. Without a
 * memory cache, or with one that fails, nothing counts as looked at: every
 * sync runs, as it did before syncs were paced.
 */
class RecentSyncsTest extends TestCase {
	use BuildsLinkCaches;

	public function testALookIsNotedForHalfTheIntervalUnderItsFileId(): void {
		$cache = new InMemoryCache();
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->expects($this->once())->method('createDistributed')->with('etherpad_nextcloud/recent-syncs/')->willReturn($cache);
		$syncs = new RecentSyncs($factory, $this->interval(5), $this->createMock(LoggerInterface::class));

		$syncs->note(138);

		$this->assertSame(['138' => 2], $cache->ttls);
		$this->assertTrue($syncs->has(138));
		$this->assertFalse($syncs->has(139));
	}

	public function testWithoutAMemoryCacheNothingIsNoted(): void {
		$syncs = new RecentSyncs($this->noMemoryCache(), $this->interval(120), $this->createMock(LoggerInterface::class));

		$syncs->note(138);

		$this->assertFalse($syncs->has(138));
	}

	/** An Error too, as a cache misconfigured can throw: the sync it serves goes on, and the log says why. */
	public function testACacheThatFailsCountsNothingAsLookedAt(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willThrowException(new \Error('Redis went away'));
		$cache->method('get')->willThrowException(new \Error('Redis went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('debug')->with('The memory cache failed, so the timed syncs of a pad run as if none had looked at it while it fails.', $this->anything());
		$syncs = new RecentSyncs($this->cacheFactoryFor($cache), $this->interval(120), $logger);

		$syncs->note(138);

		$this->assertFalse($syncs->has(138));
	}

	private function interval(int $seconds): AppConfigService {
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('getSyncIntervalSeconds')->willReturn($seconds);
		return $appConfig;
	}
}
