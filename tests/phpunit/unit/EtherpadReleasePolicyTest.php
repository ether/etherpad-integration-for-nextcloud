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
	public function testEtherpadThreeCanKeepTheCookieFromScripts(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('detectReleaseVersion')->willReturn('3.3.3');

		self::assertTrue($this->policy($client, $this->config())->supportsHttpOnlySessionCookie());
	}

	public function testEtherpadTwoStillNeedsAReadableCookie(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('detectReleaseVersion')->willReturn('2.7.3');

		self::assertFalse($this->policy($client, $this->config())->supportsHttpOnlySessionCookie());
	}

	/** The boundary itself, from both sides. */
	public function testTheBoundaryIsThreeZeroZero(): void {
		$before = $this->createMock(EtherpadClient::class);
		$before->method('detectReleaseVersion')->willReturn('2.9.9');
		self::assertFalse($this->policy($before, $this->config())->supportsHttpOnlySessionCookie());

		$exactly = $this->createMock(EtherpadClient::class);
		$exactly->method('detectReleaseVersion')->willReturn('3.0.0');
		self::assertTrue($this->policy($exactly, $this->config())->supportsHttpOnlySessionCookie());
	}

	/**
	 * No answer is not a licence to harden: a wrong `true` costs every
	 * protected pad on the instance, a wrong `false` costs a hardening.
	 */
	public function testAnUnreachableEtherpadKeepsTheCookieReadable(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('detectReleaseVersion')
			->willThrowException(new EtherpadClientException('Connection timed out'));

		self::assertFalse($this->policy($client, $this->config())->supportsHttpOnlySessionCookie());
	}

	/** A blip does not undo what was already known about the pad server. */
	public function testAFailedCheckKeepsTheLastKnownRelease(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('detectReleaseVersion')
			->willThrowException(new EtherpadClientException('Connection timed out'));

		$config = $this->config(['etherpad_release_version' => '3.3.3', 'etherpad_release_checked_at' => '0']);

		self::assertTrue($this->policy($client, $config)->supportsHttpOnlySessionCookie());
	}

	/** This runs on the open path; a fresh answer is not asked for twice. */
	public function testAFreshAnswerIsNotAskedForAgain(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('detectReleaseVersion');

		$config = $this->config([
			'etherpad_release_version' => '3.3.3',
			'etherpad_release_checked_at' => (string)(1_700_000_000 - 60),
		]);

		self::assertTrue($this->policy($client, $config)->supportsHttpOnlySessionCookie());
	}

	/**
	 * And a stale one is. The downgrade is why: an Etherpad that goes back
	 * to 2.x would otherwise keep being sent a cookie its pad app cannot
	 * read.
	 */
	public function testAStaleAnswerIsCheckedAgain(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::once())->method('detectReleaseVersion')->willReturn('2.7.3');

		$config = $this->config([
			'etherpad_release_version' => '3.3.3',
			'etherpad_release_checked_at' => (string)(1_700_000_000 - 3601),
		]);

		self::assertFalse($this->policy($client, $config)->supportsHttpOnlySessionCookie());
	}

	/** @param array<string,string> $stored */
	private function config(array $stored = []): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $stored[$key] ?? $default
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);
		return $config;
	}

	private function policy(EtherpadClient $client, IConfig $config): EtherpadReleasePolicy {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1_700_000_000);
		return new EtherpadReleasePolicy($client, $config, $timeFactory, $this->createMock(LoggerInterface::class));
	}
}
