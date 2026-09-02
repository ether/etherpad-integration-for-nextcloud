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
			's.old' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() - 3600],
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
		// Well past expiry: a session that ran out ten seconds ago is
		// treated as live here, because Etherpad's clock decides and ours
		// may be ahead of it.
		for ($i = 0; $i < 800; $i++) {
			$sessions['dead.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() - 3600];
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

	/**
	 * Etherpad judges `validUntil` against its own clock. If ours runs
	 * ahead, a session we would call expired is one it still honours, and
	 * skipping it leaves exactly the access a logout is meant to take away.
	 */
	public function testRevokesASessionThatOnlyOurClockCallsExpired(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.justexpired' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() - 30],
			's.longgone' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() - 3600],
		]);
		$client->expects(self::once())->method('deleteSession')->with('s.justexpired');

		self::assertSame(1, $this->revoker($client)->revokeAll('alice'));
	}

	/**
	 * The budget only describes the run if it reaches the calls it bounds.
	 * A deadline checked between them says when the last one may start, not
	 * when it must end – with the client's own timeout that is two seconds
	 * promised and seventeen possible, inside a logout somebody is waiting
	 * for.
	 */
	public function testGivesEveryCallWhatIsLeftOfTheBudget(): void {
		$client = $this->createMock(EtherpadClient::class);
		$listingTimeout = 'unset';
		$client->method('listSessionsOfAuthor')->willReturnCallback(
			static function (string $authorId, ?int $timeout = null) use (&$listingTimeout): array {
				$listingTimeout = $timeout;
				return ['s.one' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600]];
			}
		);
		$deleteTimeout = 'unset';
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id, ?int $timeout = null) use (&$deleteTimeout): void {
				$deleteTimeout = $timeout;
			}
		);

		$this->revoker($client)->revokeAll('alice');

		foreach (['listing' => $listingTimeout, 'delete' => $deleteTimeout] as $what => $timeout) {
			self::assertNotSame('unset', $timeout, "the {$what} was never made");
			self::assertNotNull($timeout, "the {$what} went out with the client default");
			self::assertLessThanOrEqual(2, $timeout, "the {$what} may not outlast the budget");
			self::assertGreaterThanOrEqual(1, $timeout);
		}
	}

	/**
	 * The ceiling must not spend itself on the oldest sessions and leave
	 * the one in front of the person who just logged out.
	 *
	 * The listing arrives in the author index's order, roughly oldest
	 * first, so twenty-six opens of one pad meant revoking twenty-five and
	 * leaving the only one that mattered — the shared-computer case failing
	 * against a perfectly healthy pad server.
	 */
	public function testTakesTheSessionThisBrowserIsCarryingFirst(): void {
		$sessions = [];
		for ($i = 0; $i < 30; $i++) {
			$sessions['s.old' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600];
		}
		$sessions['s.inthecookie'] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600];

		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn($sessions);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id) use (&$removed): void {
				$removed[] = $id;
			}
		);

		$this->revoker($client, carriedIds: ['s.inthecookie'])->revokeAll('alice');

		self::assertContains('s.inthecookie', $removed, 'the cookie session outlived the ceiling');
		self::assertSame('s.inthecookie', $removed[0], 'and it should have gone first');
	}

	/** An id the cookie carries for some other author changes nothing. */
	public function testIgnoresCarriedIdsThatAreNotThisAuthorsSessions(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.mine' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600],
		]);
		$client->expects(self::once())->method('deleteSession')->with('s.mine');

		self::assertSame(1, $this->revoker($client, carriedIds: ['s.somebodyelse'])->revokeAll('alice'));
	}

	/**
	 * Reading the cached author is a database round trip. If it took the
	 * budget, starting a listing on top of it overruns by a whole call,
	 * because the floor under callTimeout() hands it a second it has not
	 * got.
	 */
	public function testDoesNotStartTheListingWithoutTimeForIt(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('listSessionsOfAuthor');

		$sessions = $this->createMock(PadSessionService::class);
		$sessions->method('cachedAuthorId')->willReturnCallback(
			static function (): string {
				usleep(2_100_000);
				return self::AUTHOR;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$revoker = new PadSessionRevoker($client, $sessions, $logger);

		self::assertSame(0, $revoker->revokeAll('alice'));
	}

	/**
	 * A live session the pad server refused is exactly as left behind as
	 * one the budget never reached, and the summary is what says whether a
	 * logout finished its job.
	 */
	public function testCountsARefusedDeleteAsLeftBehind(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.ok' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600],
			's.refused' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600],
		]);
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id): void {
				if ($id === 's.refused') {
					throw new EtherpadClientException('internal error');
				}
			}
		);

		$reported = null;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$reported): void {
				$reported = $context['leftToExpire'] ?? null;
			}
		);

		self::assertSame(1, $this->revoker($client, logger: $logger)->revokeAll('alice'));
		self::assertSame(1, $reported, 'the refused session is left behind, not merely warned about');
	}

	/**
	 * A run that removed nothing must not be logged as one that revoked.
	 * That line is the shape of the failure an admin greps for.
	 */
	public function testDoesNotClaimToHaveRevokedWhenItDidNot(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.refused' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600],
		]);
		$client->method('deleteSession')->willThrowException(new EtherpadClientException('internal error'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('info');
		$logger->expects(self::exactly(2))->method('warning');

		self::assertSame(0, $this->revoker($client, logger: $logger)->revokeAll('alice'));
	}

	private function revoker(
		EtherpadClient $client,
		string $author = self::AUTHOR,
		?LoggerInterface $logger = null,
		array $carriedIds = [],
	): PadSessionRevoker {
		$sessions = $this->createMock(PadSessionService::class);
		$sessions->method('cachedAuthorId')->willReturn($author);
		$sessions->method('carriedSessionIds')->willReturn($carriedIds);

		return new PadSessionRevoker(
			$client,
			$sessions,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
