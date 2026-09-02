<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An Etherpad session is a bearer token with a lifetime: nothing about
 * losing the share, the file or the account reaches it, and the only thing
 * that ever removed one was deleting its whole group.
 */
class PadSessionRevokerTest extends TestCase {
	private const AUTHOR = 'a.author';

	public function testRemovesEverySessionOfTheUser(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->with(self::AUTHOR)->willReturn([
			's.one' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600],
			's.two' => ['groupID' => 'g.BBBBBBBBBBBBBBBB', 'validUntil' => time() + 3600],
		]);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id) use (&$removed): void {
				$removed[] = $id;
			}
		);

		self::assertSame(2, $this->revoker($client)->revokeAll('alice'));
		self::assertSame(['s.one', 's.two'], $removed);
	}

	/** No author means no session was ever issued, and no round trip. */
	public function testAsksNothingForAUserWhoNeverOpenedAProtectedPad(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('listSessionsOfAuthor');

		self::assertSame(0, $this->revoker($client, author: '')->revokeAll('alice'));
	}

	/**
	 * Best effort: this runs beside a logout or an unshare, and neither may
	 * fail because a pad server is unreachable.
	 */
	public function testSurvivesAnUnreachablePadServer(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')
			->willThrowException(new EtherpadClientException('Connection timed out'));

		self::assertSame(0, $this->revoker($client)->revokeAll('alice'));
	}

	/** A session already gone is the outcome asked for, not a failure. */
	public function testCountsOnlyWhatItActuallyRemoved(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.gone' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600],
			's.here' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600],
		]);
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id): void {
				if ($id === 's.gone') {
					throw new EtherpadClientException('sessionID does not exist');
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		self::assertSame(1, $this->revoker($client, logger: $logger)->revokeAll('alice'));
	}

	/**
	 * Etherpad keeps expired sessions until something deletes them, so an
	 * author who has used protected pads for a while carries hundreds of
	 * them. They grant nothing, and this runs inside a logout the user is
	 * waiting for — one round trip each would put the whole backlog in
	 * front of them.
	 */
	public function testLeavesExpiredSessionsToABackgroundJob(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.old' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() - 1],
			's.live' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600],
		]);
		$client->expects(self::once())->method('deleteSession')->with('s.live');

		self::assertSame(1, $this->revoker($client)->revokeAll('alice'));
	}

	/**
	 * Each delete is its own call with the client's full timeout behind it,
	 * and a user who has opened pads all morning holds one live session per
	 * open. Without a ceiling a half-broken Etherpad holds the logout for
	 * minutes; what does not fit is left to expire.
	 */
	public function testLeavesTheTailToExpireRatherThanHoldingTheRequest(): void {
		$live = [];
		for ($i = 0; $i < 40; $i++) {
			$live['s.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600];
		}
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn($live);
		$client->expects(self::exactly(25))->method('deleteSession');

		self::assertSame(25, $this->revoker($client)->revokeAll('alice'));
	}

	/**
	 * The ceiling counts attempts, not successes. An Etherpad that fails
	 * fast — a rotated api key, a 500 — would otherwise never reach a limit
	 * counted in completed deletes, and spend one call and one warning per
	 * live session.
	 */
	public function testAFastFailingPadServerStillHitsTheCeiling(): void {
		$live = [];
		for ($i = 0; $i < 400; $i++) {
			$live['s.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600];
		}
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn($live);
		$client->expects(self::exactly(25))
			->method('deleteSession')
			->willThrowException(new EtherpadClientException('Etherpad API error: internal error'));

		self::assertSame(0, $this->revoker($client)->revokeAll('alice'));
	}

	/**
	 * And what is reported as left behind is only ever a live session. The
	 * expired tail is hundreds of entries; counting it there made the one
	 * number that says "this revoke was incomplete" useless.
	 */
	public function testTheExpiredTailIsNotReportedAsLeftBehind(): void {
		$sessions = [];
		for ($i = 0; $i < 30; $i++) {
			$sessions['live.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600];
		}
		for ($i = 0; $i < 800; $i++) {
			$sessions['dead.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() - 10];
		}
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn($sessions);

		$reported = null;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$reported): void {
				$reported = $context['leftToExpire'] ?? null;
			}
		);

		self::assertSame(25, $this->revoker($client, logger: $logger)->revokeAll('alice'));
		self::assertSame(5, $reported);
	}

	private function revoker(
		EtherpadClient $client,
		string $author = self::AUTHOR,
		?LoggerInterface $logger = null,
	): PadSessionRevoker {
		$sessions = $this->createMock(PadSessionService::class);
		$sessions->method('cachedAuthorId')->willReturn($author);

		return new PadSessionRevoker(
			$client,
			$sessions,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
