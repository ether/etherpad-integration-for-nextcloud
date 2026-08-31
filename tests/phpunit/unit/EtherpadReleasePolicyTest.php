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

	protected function setUp(): void {
		parent::setUp();
		$this->stored = new \ArrayObject();
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
		$this->store(host: 'https://pad.example.test', release: '3.3.3', checkedAt: 0);

		self::assertTrue($this->policy($this->failing())->supportsHttpOnlySessionCookie());
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

		return new EtherpadReleasePolicy($client, $config, $timeFactory, $this->createMock(LoggerInterface::class));
	}
}
