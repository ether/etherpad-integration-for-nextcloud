<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\EtherpadReleasePolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Up to Etherpad 2.7.3 the pad app reads `sessionID` in the browser, so an
 * HttpOnly cookie locks the user out of every protected pad. From 3.0.0 the
 * server reads it out of the socket.io handshake instead.
 */
class EtherpadReleasePolicyTest extends TestCase {
	/** App config that actually remembers what was written to it. */
	private \ArrayObject $stored;
	private int $now = 1_700_000_000;
	private \OCP\ICacheFactory $cacheFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->stored = new \ArrayObject();
		$this->cacheFactory = $this->noCache();
	}

	public function testEtherpadThreeCanKeepTheCookieFromScripts(): void {
		self::assertTrue($this->policy($this->answering('3.3.3'))->supportsHttpOnlySessionCookie());
	}

	public function testEtherpadTwoStillNeedsAReadableCookie(): void {
		self::assertFalse($this->policy($this->answering('2.7.3'))->supportsHttpOnlySessionCookie());
	}

	/** The boundary itself, from both sides. */
	public function testTheBoundaryIsThreeZeroZero(): void {
		self::assertFalse($this->policy($this->answering('2.9.9'))->supportsHttpOnlySessionCookie());
		$this->stored = new \ArrayObject();
		self::assertTrue($this->policy($this->answering('3.0.0'))->supportsHttpOnlySessionCookie());
	}

	/**
	 * No answer is not a licence to harden: a wrong `true` costs every
	 * protected pad on the instance, a wrong `false` costs a hardening.
	 */
	public function testAnUnreachableEtherpadKeepsTheCookieReadable(): void {
		self::assertFalse($this->policy($this->failing())->supportsHttpOnlySessionCookie());
	}

	/** A blip does not undo what was already known about the pad server. */
	public function testAFailedCheckKeepsTheLastKnownRelease(): void {
		$this->store(host: 'https://pad.example.test', release: '3.3.3', checkedAt: $this->now - 3601);

		self::assertTrue($this->policy($this->failing())->supportsHttpOnlySessionCookie());
	}

	/**
	 * But not forever. The TTL is justified by the cache turning over, and
	 * on this path it never did: an admin moving back to an Etherpad 2 whose
	 * `/health` cannot be reached would have had every protected pad locked
	 * out for good, asked once a minute.
	 */
	public function testAReleaseNothingHasConfirmedForHoursStopsCounting(): void {
		$this->store(host: 'https://pad.example.test', release: '3.3.3', checkedAt: $this->now - 6 * 3600);

		self::assertFalse($this->policy($this->failing())->supportsHttpOnlySessionCookie());
	}

	/**
	 * A clock that jumps backwards leaves a stamp ahead of now, and a
	 * negative age is younger than every window there is — the cache would
	 * freeze until real time caught up.
	 */
	public function testAStampFromTheFutureIsNotTreatedAsFresh(): void {
		$this->store(host: 'https://pad.example.test', release: '3.3.3', checkedAt: $this->now + 7200);
		$client = $this->answering('2.7.3');
		$client->expects(self::once())->method('detectReleaseVersion')->willReturn('2.7.3');

		self::assertFalse($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	/**
	 * The failure mode of getting the flag wrong is total, so it does not
	 * rest on detection alone.
	 */
	public function testAnAdminCanForceTheCookieReadable(): void {
		$this->stored[EtherpadReleasePolicy::OVERRIDE_KEY] = 'no';
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('detectReleaseVersion');

		self::assertFalse($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	public function testAnAdminCanForceTheCookieHttpOnly(): void {
		$this->stored[EtherpadReleasePolicy::OVERRIDE_KEY] = 'yes';
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('detectReleaseVersion');

		self::assertTrue($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	public function testAnythingElseMeansDetect(): void {
		$this->stored[EtherpadReleasePolicy::OVERRIDE_KEY] = 'auto';

		self::assertTrue($this->policy($this->answering('3.3.3'))->supportsHttpOnlySessionCookie());
	}

	/**
	 * The override is read before anything else, and on many requests it is
	 * the first app-config read there is — so a database blip there would
	 * escape a predicate that promises it cannot fail an open.
	 */
	public function testAConfigReadThatFailsIsAnAnswerAndNotAnException(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getApiHost')->willReturn('https://pad.example.test');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willThrowException(new \RuntimeException('database has gone away'));
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn($this->now);

		$policy = new EtherpadReleasePolicy(
			$client,
			$config,
			$timeFactory,
			$this->noCache(),
			$this->createMock(LoggerInterface::class),
		);

		self::assertFalse($policy->supportsHttpOnlySessionCookie());
	}

	/**
	 * The class promises never to fail an open. `getApiHost()` throws when
	 * no host is configured at all, and it sits outside the detection call.
	 */
	public function testAnUnconfiguredHostIsAnAnswerAndNotAnException(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getApiHost')
			->willThrowException(new EtherpadClientException('Etherpad host is not configured.'));

		self::assertFalse($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	/**
	 * At the moment the hour turns, every open in flight sees the same stale
	 * stamp. Claiming the check first means one of them asks.
	 */
	public function testConcurrentOpensDoNotEachProbe(): void {
		// The claim only works where something is actually shared between
		// workers: Nextcloud loads app config once per request, so a
		// timestamp written here is invisible to a request already running.
		$this->cacheFactory = $this->sharedCache();
		$this->store(host: 'https://pad.example.test', release: '3.3.3', checkedAt: $this->now - 3601);
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getApiHost')->willReturn('https://pad.example.test');
		$client->expects(self::once())->method('detectReleaseVersion')
			->willReturnCallback(function () use (&$client): string {
				// What a second request arriving mid-flight would see.
				self::assertTrue($this->policy($client)->supportsHttpOnlySessionCookie());
				return '3.3.3';
			});

		self::assertTrue($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	/**
	 * Nothing to coordinate with is not a reason to skip the check: a
	 * single-node instance without a memory cache has one worker per open.
	 */
	public function testWithoutAMemoryCacheTheCheckStillHappens(): void {
		$this->cacheFactory = $this->noCache();
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getApiHost')->willReturn('https://pad.example.test');
		$client->expects(self::exactly(2))->method('detectReleaseVersion')->willReturn('3.3.3');

		self::assertTrue($this->policy($client)->supportsHttpOnlySessionCookie());
		$this->stored['etherpad_release_checked_at'] = (string)($this->now - 3601);
		self::assertTrue($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	/** A release with a suffix is still a 3. */
	public function testAPreReleaseOfAMajorCountsAsThatMajor(): void {
		self::assertTrue(EtherpadReleasePolicy::allowsHttpOnly('3.0.0-beta.1'));
		self::assertFalse(EtherpadReleasePolicy::allowsHttpOnly('2.9.9-rc.1'));
		self::assertFalse(EtherpadReleasePolicy::allowsHttpOnly(''));
	}

	/** This runs on the open path; a fresh answer is not asked for twice. */
	public function testAFreshAnswerIsNotAskedForAgain(): void {
		$this->store(host: 'https://pad.example.test', release: '3.3.3', checkedAt: $this->now - 60);
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getApiHost')->willReturn('https://pad.example.test');
		$client->expects(self::never())->method('detectReleaseVersion');

		self::assertTrue($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	/**
	 * And a stale one is. The downgrade is why: an Etherpad that goes back
	 * to 2.x would otherwise keep being sent a cookie its pad app cannot
	 * read.
	 */
	public function testAStaleAnswerIsCheckedAgain(): void {
		$this->store(host: 'https://pad.example.test', release: '3.3.3', checkedAt: $this->now - 3601);
		$client = $this->answering('2.7.3');
		$client->expects(self::once())->method('detectReleaseVersion')->willReturn('2.7.3');

		self::assertFalse($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	/**
	 * A pad server that accepts the connection and then says nothing costs
	 * every open the health timeout. The failure path returned the last
	 * known release but recorded nothing, so the next open started over.
	 */
	public function testAFailedCheckIsNotRepeatedOnTheNextOpen(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getApiHost')->willReturn('https://pad.example.test');
		$client->expects(self::once())
			->method('detectReleaseVersion')
			->willThrowException(new EtherpadClientException('Connection timed out'));

		$policy = $this->policy($client);
		self::assertFalse($policy->supportsHttpOnlySessionCookie());
		$this->now += 30;
		self::assertFalse($policy->supportsHttpOnlySessionCookie());
	}

	/** The backoff is a minute, not an hour: a failure told us nothing. */
	public function testAFailedCheckIsRetriedAfterTheBackoff(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getApiHost')->willReturn('https://pad.example.test');
		$client->expects(self::exactly(2))
			->method('detectReleaseVersion')
			->willReturnOnConsecutiveCalls(
				self::throwException(new EtherpadClientException('Connection timed out')),
				'3.3.3',
			);

		$policy = $this->policy($client);
		self::assertFalse($policy->supportsHttpOnlySessionCookie());
		$this->now += 61;
		self::assertTrue($policy->supportsHttpOnlySessionCookie());
	}

	/**
	 * What one pad server says about itself is not true of the next one. An
	 * admin repointing the app from an Etherpad 3 to an Etherpad 2 must not
	 * have it sent a cookie its pad app cannot read.
	 */
	public function testARepointedHostDoesNotInheritTheOldOnesRelease(): void {
		$this->store(host: 'https://old.pad.test', release: '3.3.3', checkedAt: $this->now);
		$client = $this->answering('2.7.3');
		$client->method('getApiHost')->willReturn('https://new.pad.test');

		self::assertFalse($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	/** And not even when the new host cannot be reached at all. */
	public function testARepointedHostThatCannotBeReachedIsUnknown(): void {
		$this->store(host: 'https://old.pad.test', release: '3.3.3', checkedAt: $this->now);
		$client = $this->failing();
		$client->method('getApiHost')->willReturn('https://new.pad.test');

		self::assertFalse($this->policy($client)->supportsHttpOnlySessionCookie());
	}

	private function store(string $host, string $release, int $checkedAt): void {
		$this->stored['etherpad_release_host'] = $host;
		$this->stored['etherpad_release_version'] = $release;
		$this->stored['etherpad_release_checked_at'] = (string)$checkedAt;
	}

	private function answering(string $release): EtherpadClient {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getApiHost')->willReturn('https://pad.example.test');
		$client->method('detectReleaseVersion')->willReturn($release);
		return $client;
	}

	private function failing(): EtherpadClient {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getApiHost')->willReturn('https://pad.example.test');
		$client->method('detectReleaseVersion')
			->willThrowException(new EtherpadClientException('Connection timed out'));
		return $client;
	}

	private function policy(EtherpadClient $client): EtherpadReleasePolicy {
		$stored = $this->stored;
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($stored[$key] ?? $default)
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($stored): void {
				$stored[$key] = $value;
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new EtherpadReleasePolicy(
			$client,
			$config,
			$timeFactory,
			$this->cacheFactory,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A distributed cache shared between the policies a test builds, the way
	 * one is shared between workers. Without a real one the claim is a
	 * no-op, which is what a single-node instance gets.
	 */
	private function sharedCache(): \OCP\ICacheFactory {
		$held = new \ArrayObject();
		$cache = $this->createMock(\OCP\ICache::class);
		$cache->method('add')->willReturnCallback(
			static function (string $key) use ($held): bool {
				if (isset($held[$key])) {
					return false;
				}
				$held[$key] = true;
				return true;
			}
		);
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		return $factory;
	}

	/** No memory cache: one worker per open, nothing to coordinate with. */
	private function noCache(): \OCP\ICacheFactory {
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		return $factory;
	}
}
