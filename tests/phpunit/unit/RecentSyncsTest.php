<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\RecentSyncs;
use OCA\EtherpadNextcloud\Tests\Support\BuildsLinkCaches;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryCache;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which files a sync looked at within the last half interval, rounded up
 * and counted from the sync's start. Without a memory cache, or with one
 * that fails, nothing counts as looked at, and every sync runs.
 */
class RecentSyncsTest extends TestCase {
	use BuildsLinkCaches;

	/** Half of five seconds, rounded up, less what the look took - and a second at least. */
	public function testALookIsNotedForHalfTheIntervalUnderItsFileId(): void {
		$cache = new InMemoryCache();
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->expects($this->once())->method('createDistributed')->with('etherpad_nextcloud/recent-syncs/')->willReturn($cache);
		$clock = new FixedClock();
		$syncs = new RecentSyncs($factory, $this->interval(5), $clock, $this->createMock(LoggerInterface::class));

		$syncs->note(138, FixedClock::NOW);
		$syncs->note(140, FixedClock::NOW - 1);
		$syncs->note(141, FixedClock::NOW - 9);

		$this->assertSame(['138' => 3, '140' => 2, '141' => 1], $cache->ttls);
		$this->assertSame(FixedClock::NOW, $cache->values['138'], 'when the look began');
		$this->assertTrue($syncs->has(138));
		$this->assertFalse($syncs->has(139));
		$clock->advance(3);
		$this->assertFalse($syncs->has(138), 'measured, whatever the cache keeps');
	}

	/** A note kept under an interval of an hour holds no longer than half the interval the admin set since. */
	public function testAShortenedIntervalHoldsForTheNotesAlreadyKept(): void {
		$cache = new InMemoryCache();
		$seconds = 3600;
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('getSyncIntervalSeconds')->willReturnCallback(static function () use (&$seconds): int {
			return $seconds;
		});
		$clock = new FixedClock();
		$syncs = new RecentSyncs($this->cacheFactoryFor($cache), $appConfig, $clock, $this->createMock(LoggerInterface::class));

		$syncs->note(138, FixedClock::NOW);
		$this->assertSame(['138' => 1800], $cache->ttls);
		$seconds = 5;
		$clock->advance(2);
		$this->assertTrue($syncs->has(138));
		$clock->advance(1);
		$this->assertFalse($syncs->has(138));
	}

	public function testWithoutAMemoryCacheNothingIsNoted(): void {
		$syncs = new RecentSyncs($this->noMemoryCache(), $this->interval(120), new FixedClock(), $this->createMock(LoggerInterface::class));

		$syncs->note(138, FixedClock::NOW);

		$this->assertFalse($syncs->has(138));
	}

	/** An Error too, as a cache misconfigured can throw: the sync it serves goes on, and the log says why. */
	public function testACacheThatFailsCountsNothingAsLookedAt(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willThrowException(new \Error('Redis went away'));
		$cache->method('get')->willThrowException(new \Error('Redis went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('debug')->with(
			'The memory cache failed, so the timed syncs of a pad run as if none had looked at it while it fails.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['error'] === \Error::class),
		);
		$syncs = new RecentSyncs($this->cacheFactoryFor($cache), $this->interval(120), new FixedClock(), $logger);

		$syncs->note(138, FixedClock::NOW);

		$this->assertFalse($syncs->has(138));
	}

	private function interval(int $seconds): AppConfigService {
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('getSyncIntervalSeconds')->willReturn($seconds);
		return $appConfig;
	}
}
