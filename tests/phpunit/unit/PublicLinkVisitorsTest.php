<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PublicLinkCache;
use OCA\EtherpadNextcloud\Service\PublicLinkVisitors;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\ISession;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A visitor of a public link opens as an author of their own while their
 * Nextcloud session lives; past the link's count of new visitors for the
 * hour, as the link itself.
 */
class PublicLinkVisitorsTest extends TestCase {
	private const LINK = 'public-share:a-share-token';
	private const TOKEN = 'a-share-token';

	/** @var \ArrayObject<string, mixed> */
	private \ArrayObject $session;
	/** @var \ArrayObject<string, mixed> */
	private \ArrayObject $held;
	private FixedClock $clock;
	private int $ids = 0;

	protected function setUp(): void {
		$this->session = new \ArrayObject();
		$this->held = new \ArrayObject();
		$this->clock = new FixedClock();
	}

	/** The same visitor while their session lives; another link, another one. */
	public function testAVisitorKeepsTheirAuthorWhileTheirSessionLives(): void {
		$visitors = $this->visitors($this->memcache());

		$first = $visitors->uidFor(self::TOKEN);
		$again = $visitors->uidFor(self::TOKEN);
		$elsewhere = $visitors->uidFor('another-token');

		$this->assertMatchesRegularExpression('/^' . preg_quote(self::LINK, '/') . ':[0-9a-f]{32}$/', $first);
		$this->assertSame($first, $again);
		$this->assertStringStartsWith('public-share:another-token:', $elsewhere);
		$this->assertNotSame(substr($first, -32), substr($elsewhere, -32));
	}

	/**
	 * A new visitor is cheap - a request without the session cookie is
	 * one - so a link takes 250 an hour. Any after them open as the link,
	 * the next hour counts anew, and a visitor already admitted is not
	 * counted again.
	 */
	public function testALinkTakesItsNewVisitorsForTheHourThenOpensAsItself(): void {
		$cache = $this->memcache();
		$admitted = [];
		for ($i = 0; $i < PublicLinkVisitors::PER_HOUR; $i++) {
			$this->session = new \ArrayObject();
			$admitted[] = $this->visitors($cache)->uidFor(self::TOKEN);
		}
		$returning = $this->session;

		$this->session = new \ArrayObject();
		$past = $this->visitors($cache)->uidFor(self::TOKEN);
		$this->session = $returning;
		$back = $this->visitors($cache)->uidFor(self::TOKEN);
		$this->clock->advance(3600);
		$this->session = new \ArrayObject();
		$nextHour = $this->visitors($cache)->uidFor(self::TOKEN);

		$this->assertCount(PublicLinkVisitors::PER_HOUR, array_unique($admitted));
		foreach ($admitted as $uid) {
			$this->assertStringStartsWith(self::LINK . ':', $uid);
		}
		$this->assertSame(self::LINK, $past);
		$this->assertSame($admitted[PublicLinkVisitors::PER_HOUR - 1], $back);
		$this->assertStringStartsWith(self::LINK . ':', $nextHour);
	}

	/**
	 * One who comes back keeps their id, and is counted again in every new
	 * hour: ids gathered over hours buy no more visitors of their own. In
	 * an hour that is full they open as the link, and as themselves again
	 * in the next.
	 */
	public function testAVisitorWhoComesBackIsCountedAgainInANewHour(): void {
		$cache = $this->memcache();
		$own = $this->visitors($cache)->uidFor(self::TOKEN);
		$returning = $this->session;

		$this->clock->advance(3600);
		for ($i = 0; $i < PublicLinkVisitors::PER_HOUR; $i++) {
			$this->session = new \ArrayObject();
			$this->visitors($cache)->uidFor(self::TOKEN);
		}
		$this->session = $returning;
		$fullHour = $this->visitors($cache)->uidFor(self::TOKEN);
		$this->clock->advance(3600);
		$nextHour = $this->visitors($cache)->uidFor(self::TOKEN);

		$this->assertSame(self::LINK, $fullHour);
		$this->assertSame($own, $nextHour);
	}

	/** Once an hour for the link, however many more open as it. */
	public function testALinkPastItsCountIsSaidOnceAnHour(): void {
		$cache = $this->memcache();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		for ($i = 0; $i < PublicLinkVisitors::PER_HOUR + 3; $i++) {
			$this->session = new \ArrayObject();
			$this->visitors($cache, $logger)->uidFor(self::TOKEN);
		}
	}

	/**
	 * Without a memory cache nothing could bound the authors a loop makes,
	 * so every visitor opens as the link, as before visitors had their own.
	 */
	public function testWithoutAMemoryCacheEveryVisitorOpensAsTheLink(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$factory->expects($this->never())->method('createDistributed');

		$this->assertSame(self::LINK, $this->visitors($factory)->uidFor(self::TOKEN));
		$this->assertSame([], $this->session->getArrayCopy());
	}

	/** A cache that cannot count - not a memory cache, failing, or not counting - opens as the link, and a failing one says so. */
	public function testACacheThatCannotCountOpensAsTheLink(): void {
		$plain = $this->createMock(ICacheFactory::class);
		$plain->method('isAvailable')->willReturn(true);
		$plain->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$this->assertSame(self::LINK, $this->visitors($plain)->uidFor(self::TOKEN));

		$notCounting = $this->createMock(IMemcache::class);
		$notCounting->method('inc')->willReturn(false);
		$silent = $this->createMock(ICacheFactory::class);
		$silent->method('isAvailable')->willReturn(true);
		$silent->method('createDistributed')->willReturn($notCounting);
		$this->assertSame(self::LINK, $this->visitors($silent)->uidFor(self::TOKEN));

		$failing = $this->createMock(ICacheFactory::class);
		$failing->method('isAvailable')->willReturn(true);
		$failing->method('createDistributed')->willThrowException(new \RuntimeException('Redis server went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$this->assertSame(self::LINK, $this->visitors($failing, $logger)->uidFor(self::TOKEN));
		$this->assertSame([], $this->session->getArrayCopy());
	}

	/**
	 * Counted in a later hour - by a web server whose clock is ahead - is
	 * counted for this one: no second count, no flapping between servers.
	 */
	public function testAVisitorCountedInALaterHourIsNotCountedAgain(): void {
		$this->clock->advance(3600);
		$own = $this->visitors($this->memcache())->uidFor(self::TOKEN);
		$this->clock->advance(-3600);
		$untouched = $this->createMock(ICacheFactory::class);
		$untouched->expects($this->never())->method('createDistributed');

		$this->assertSame($own, $this->visitors($untouched)->uidFor(self::TOKEN));
	}

	/** A visitor's uid is told from the link's and a user's by its form. */
	public function testTellsAVisitorByTheirUid(): void {
		self::assertTrue(PublicLinkVisitors::isVisitor('public-share:token:' . str_repeat('0f', 16)));
		self::assertFalse(PublicLinkVisitors::isVisitor('public-share:token'));
		self::assertFalse(PublicLinkVisitors::isVisitor('public-share:token:' . str_repeat('0f', 15)));
		self::assertFalse(PublicLinkVisitors::isVisitor('public-share:token:' . str_repeat('0F', 16)));
		self::assertFalse(PublicLinkVisitors::isVisitor('admin'));
	}

	/** Neither the session nor the cache holds the token: their keys are HMACs. */
	public function testNoKeyCarriesTheToken(): void {
		$this->visitors($this->memcache())->uidFor(self::TOKEN);

		$this->assertNotEmpty($this->session->getArrayCopy());
		$this->assertNotEmpty($this->held->getArrayCopy());
		foreach ([...array_keys($this->session->getArrayCopy()), ...array_keys($this->held->getArrayCopy())] as $key) {
			$this->assertStringNotContainsString(self::TOKEN, (string)$key);
		}
	}

	private function visitors(ICacheFactory $factory, ?LoggerInterface $logger = null): PublicLinkVisitors {
		$sessionValues = $this->session;
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(static fn (string $key): mixed => $sessionValues[$key] ?? null);
		$session->method('set')->willReturnCallback(static function (string $key, mixed $value) use ($sessionValues): void {
			$sessionValues[$key] = $value;
		});
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn (int $length): string => str_pad(dechex(++$this->ids), $length, '0', STR_PAD_LEFT));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash('sha256', $message, true));
		return new PublicLinkVisitors($session, new PublicLinkCache($factory, $crypto), $random, $this->clock, $logger ?? $this->createMock(LoggerInterface::class));
	}

	/** A memory cache that counts, as `add` and `inc` do. */
	private function memcache(): ICacheFactory {
		$held = $this->held;
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturnCallback(static function (string $key, mixed $value) use ($held): bool {
			if (isset($held[$key])) {
				return false;
			}
			$held[$key] = $value;
			return true;
		});
		$cache->method('inc')->willReturnCallback(static function (string $key, int $step = 1) use ($held): int {
			$held[$key] = (int)($held[$key] ?? 0) + $step;
			return $held[$key];
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		return $factory;
	}
}
