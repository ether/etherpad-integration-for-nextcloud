<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\RecentSyncs;
use OCA\EtherpadNextcloud\Tests\Support\BuildsMemoryCaches;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryCache;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The claim a timed sync of a file needs, for half the interval, and the
 * note a sync off the timer leaves. A cache that is missing, cannot claim
 * a key, or fails lets every sync run.
 */
class RecentSyncsTest extends TestCase {
	use BuildsMemoryCaches;

	/** One claim of a file for half of five seconds, rounded up, under the file and the interval. */
	public function testATimedSyncClaimsTheFileForHalfTheInterval(): void {
		$cache = new InMemoryCache();
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->expects($this->once())->method('createDistributed')->with('etherpad_nextcloud/recent-syncs/')->willReturn($cache);
		$syncs = new RecentSyncs($factory, $this->interval(5), $this->createMock(LoggerInterface::class));

		$this->assertTrue($syncs->claim(138));
		$this->assertFalse($syncs->claim(138), 'claimed already');
		$this->assertTrue($syncs->claim(139), 'another file');
		$this->assertSame(['138/5' => 3, '139/5' => 3], $cache->ttls);
		$cache->remove('138/5');
		$this->assertTrue($syncs->claim(138), 'the window over');
	}

	/** A note holds the timed syncs off as a claim does. */
	public function testANoteHoldsTheTimedSyncsOff(): void {
		$cache = new InMemoryCache();
		$syncs = new RecentSyncs($this->cacheFactoryFor($cache), $this->interval(120), $this->createMock(LoggerInterface::class));

		$syncs->note(138);

		$this->assertSame(['138/120' => 60], $cache->ttls);
		$this->assertFalse($syncs->claim(138));
	}

	/** A claim kept under an interval of an hour holds nothing off once the admin set another. */
	public function testAChangedIntervalCountsAtOnce(): void {
		$cache = new InMemoryCache();
		$seconds = 3600;
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('getSyncIntervalSeconds')->willReturnCallback(static function () use (&$seconds): int {
			return $seconds;
		});
		$syncs = new RecentSyncs($this->cacheFactoryFor($cache), $appConfig, $this->createMock(LoggerInterface::class));

		$this->assertTrue($syncs->claim(138));
		$seconds = 5;

		$this->assertTrue($syncs->claim(138));
		$this->assertSame(['138/3600' => 1800, '138/5' => 3], $cache->ttls);
	}

	/**
	 * A cache can answer an add with false for a failure too, and a read
	 * with false as well: only a claim read back keeps the sync off. One
	 * read first, so a claim there costs no add.
	 */
	public function testOnlyAClaimReadBackKeepsTheSyncOff(): void {
		foreach ([
			'a claim there' => [[1], false, false],
			'a claim made in between' => [[null, 1], true, false],
			'nothing there, add failing' => [[null, null], true, true],
			'the reads failing too' => [[false, false], true, true],
		] as $case => [$reads, $adds, $runs]) {
			$cache = $this->createMock(IMemcache::class);
			$cache->expects($adds ? $this->once() : $this->never())->method('add')->willReturn(false);
			$cache->method('get')->willReturn(...$reads);
			$syncs = new RecentSyncs($this->cacheFactoryFor($cache), $this->interval(120), $this->createMock(LoggerInterface::class));

			$this->assertSame($runs, $syncs->claim(138), $case);
		}
	}

	/** A claim let go lets the next timed sync claim the file. */
	public function testAClaimLetGoLetsTheNextOneClaim(): void {
		$cache = new InMemoryCache();
		$syncs = new RecentSyncs($this->cacheFactoryFor($cache), $this->interval(120), $this->createMock(LoggerInterface::class));

		$this->assertTrue($syncs->claim(138));
		$syncs->release(138);

		$this->assertTrue($syncs->claim(138));
	}

	/** Not asked at all: no line of a cache that failed. */
	public function testWithoutACacheThatCanClaimEverySyncRuns(): void {
		foreach (['no memory cache' => $this->noMemoryCache(), 'one without add()' => $this->cacheFactoryFor($this->createMock(ICache::class))] as $case => $factory) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->never())->method('debug');
			$syncs = new RecentSyncs($factory, $this->interval(120), $logger);

			$syncs->note(138);

			$this->assertTrue($syncs->claim(138), $case);
			$this->assertTrue($syncs->claim(138), $case);
		}
	}

	/** An Error too, as a cache misconfigured can throw: it is logged, and the claim lets the sync run. */
	public function testACacheThatFailsLetsEverySyncRun(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willThrowException(new \Error('Redis went away'));
		$cache->method('set')->willThrowException(new \Error('Redis went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('debug')->with(
			'The memory cache failed, so the timed syncs of a pad run unpaced while it fails.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['error'] === \Error::class),
		);
		$syncs = new RecentSyncs($this->cacheFactoryFor($cache), $this->interval(120), $logger);

		$syncs->note(138);

		$this->assertTrue($syncs->claim(138));
	}

	private function interval(int $seconds): AppConfigService {
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('getSyncIntervalSeconds')->willReturn($seconds);
		return $appConfig;
	}
}
