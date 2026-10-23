<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\RecentSyncWrites;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * Which files a sync wrote within the last half interval. Without a
 * memory cache, or with one that fails, nothing counts as written: every
 * sync runs, as it did before syncs were paced.
 */
class RecentSyncWritesTest extends TestCase {
	public function testAWriteIsNotedForHalfTheIntervalUnderItsFileId(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())->method('set')->with('138', 1, 2)->willReturn(true);
		$cache->method('get')->willReturnCallback(static fn (string $key): ?int => $key === '138' ? 1 : null);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->expects($this->once())->method('createDistributed')->with('etherpad_nextcloud/sync-writes/')->willReturn($cache);
		$writes = new RecentSyncWrites($factory, $this->interval(5));

		$writes->note(138);

		$this->assertTrue($writes->has(138));
		$this->assertFalse($writes->has(139));
	}

	public function testWithoutAMemoryCacheNothingIsNoted(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$factory->expects($this->never())->method('createDistributed');
		$writes = new RecentSyncWrites($factory, $this->interval(120));

		$writes->note(138);

		$this->assertFalse($writes->has(138));
	}

	public function testACacheThatFailsCountsNothingAsWritten(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willThrowException(new \RuntimeException('Redis went away'));
		$cache->method('get')->willThrowException(new \RuntimeException('Redis went away'));
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		$writes = new RecentSyncWrites($factory, $this->interval(120));

		$writes->note(138);

		$this->assertFalse($writes->has(138));
	}

	private function interval(int $seconds): AppConfigService {
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('getSyncIntervalSeconds')->willReturn($seconds);
		return $appConfig;
	}
}
