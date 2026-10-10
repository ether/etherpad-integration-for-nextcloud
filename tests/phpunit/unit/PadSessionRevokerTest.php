<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\GroupSessionRevoker;
use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\SessionDeletes;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;

/**
 * An Etherpad session is a bearer token with a lifetime: nothing about
 * losing the share, the file or the account reaches it, and the only thing
 * that ever removed one was deleting its whole group.
 */
class PadSessionRevokerTest extends TestCase {
	private const AUTHOR = 'a.author';

	public function testRemovesEverySessionOfTheUser(): void {
		$client = $this->createMock(EtherpadClient::class);
		// A logout reads its listing whole: ending access is worth the memory.
		$client->method('listSessionsOfAuthor')->with(self::AUTHOR, self::anything(), self::anything(), self::isNull())->willReturn([
			's.one' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
			's.two' => ['groupID' => 'g.BBBBBBBBBBBBBBBB', 'validUntil' => FixedClock::NOW + 3600],
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
			's.gone' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
			's.here' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
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
			's.old' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW - 3600],
			's.live' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
		]);
		$client->expects(self::once())->method('deleteSession')->with('s.live');

		self::assertSame(1, $this->revoker($client)->revokeAll('alice'));
	}

	/**
	 * A user who has opened pads all morning holds one live session per
	 * open. Without a ceiling, a half-broken Etherpad would hold the logout
	 * for minutes; what does not fit is left to expire.
	 */
	public function testLeavesTheTailToExpireRatherThanHoldingTheRequest(): void {
		$live = [];
		for ($i = 0; $i < 40; $i++) {
			$live['s.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600];
		}
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn($live);
		$client->expects(self::exactly(25))->method('deleteSession');

		self::assertSame(25, $this->revoker($client)->revokeAll('alice'));
	}

	/**
	 * An Etherpad that fails fast - a rotated api key, a 500 - spends a few
	 * calls and lines, not one per live session: twenty refusals in a row
	 * end the revoke, and in a request five failures without an answer do,
	 * with no call spent on asking whether it answers.
	 */
	public function testAFastFailingPadServerIsStoppedEarly(): void {
		$live = [];
		for ($i = 0; $i < 400; $i++) {
			$live['s.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600];
		}
		$failures = [
			'refused' => [new EtherpadRefusedException('Etherpad API error (deleteSession): internal error'), 20],
			'no answer' => [new EtherpadClientException('Etherpad API HTTP error (500)'), 5],
		];
		foreach ($failures as $case => [$failure, $calls]) {
			$client = $this->createMock(EtherpadClient::class);
			$client->method('listSessionsOfAuthor')->willReturn($live);
			$client->expects(self::never())->method('assertAnswering');
			$client->expects(self::exactly($calls))->method('deleteSession')->willThrowException($failure);

			self::assertSame(0, $this->revoker($client)->revokeAll('alice'), $case);
		}
	}

	/**
	 * Answers that are neither deletes nor a row of refusals - sessions
	 * already gone between refusals - still stop at the ceiling of 25.
	 */
	public function testMixedAnswersStopAtTheCeiling(): void {
		$live = [];
		for ($i = 0; $i < 400; $i++) {
			$live['s.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600];
		}
		$calls = 0;
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn($live);
		$client->method('deleteSession')->willReturnCallback(static function () use (&$calls): void {
			$calls++;
			throw new EtherpadRefusedException($calls % 2 === 0 ? 'Etherpad API error (deleteSession): sessionID does not exist' : 'Etherpad API error (deleteSession): internal error');
		});

		self::assertSame(0, $this->revoker($client)->revokeAll('alice'));
		self::assertSame(25, $calls);
	}

	/**
	 * What is reported as left behind is only ever a live session: the
	 * expired tail, hundreds of entries, would hide the one number that
	 * says a revoke did not finish.
	 */
	public function testTheExpiredTailIsNotReportedAsLeftBehind(): void {
		$sessions = [];
		for ($i = 0; $i < 30; $i++) {
			$sessions['live.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600];
		}
		// Well past expiry: a session that ran out ten seconds ago is
		// treated as live here, because Etherpad's clock decides and ours
		// may be ahead of it.
		for ($i = 0; $i < 800; $i++) {
			$sessions['dead.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW - 3600];
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
			's.justexpired' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW - 30],
			's.longgone' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW - 3600],
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
				return ['s.one' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600]];
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
	 * first: after twenty-six opens of one pad, the ceiling would revoke
	 * twenty-five and leave the one that matters.
	 */
	public function testTakesTheSessionThisBrowserIsCarryingFirst(): void {
		$sessions = [];
		for ($i = 0; $i < 30; $i++) {
			$sessions['s.old' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600];
		}
		$sessions['s.inthecookie'] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600];

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

	/**
	 * A full cookie is revoked in full.
	 *
	 * Taking the carried ids first only reaches all of them if the ceiling
	 * covers a whole cookie; a lower one would revoke a prefix and leave
	 * the tail. This asks for the property rather than the number, so it
	 * stays green while the two move together and falls when they part.
	 */
	public function testRevokesEveryIdAFullCookieCanHold(): void {
		$carried = [];
		$sessions = [];
		for ($i = 0; $i < PadSessionService::MAX_SESSION_IDS; $i++) {
			$id = 's.carried' . $i;
			$carried[] = $id;
			$sessions[$id] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600];
		}
		// Plenty more behind them, so the ceiling is the only thing that
		// could stop the carried ones being reached.
		for ($i = 0; $i < 200; $i++) {
			$sessions['s.other' . $i] = ['groupID' => 'g.BBBBBBBBBBBBBBBB', 'validUntil' => FixedClock::NOW + 3600];
		}

		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn($sessions);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id) use (&$removed): void {
				$removed[] = $id;
			}
		);

		$this->revoker($client, carriedIds: $carried)->revokeAll('alice');

		foreach ($carried as $id) {
			self::assertContains($id, $removed, "{$id} was in the cookie and survived the logout");
		}
	}

	/** An id the cookie carries for some other author changes nothing. */
	public function testIgnoresCarriedIdsThatAreNotThisAuthorsSessions(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.mine' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
		]);
		$client->expects(self::once())->method('deleteSession')->with('s.mine');

		self::assertSame(1, $this->revoker($client, carriedIds: ['s.somebodyelse'])->revokeAll('alice'));
	}

	/**
	 * Reading the cached author is a database round trip. If it took the
	 * budget, no listing starts on top of it: it would overrun by a call.
	 */
	public function testDoesNotStartTheListingWithoutTimeForIt(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('listSessionsOfAuthor');

		// The lookup moves the clock rather than the test sleeping through
		// it: 2.1 seconds of real time bought one assertion and made the
		// result depend on the runner, which is what the injected clock is
		// for.
		$clock = new FixedClock();
		$sessions = $this->createMock(PadSessionService::class);
		$sessions->method('cachedAuthorId')->willReturnCallback(
			static function () use ($clock): string {
				$clock->advanceMicros(2_100_000);
				return self::AUTHOR;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$revoker = new PadSessionRevoker($client, $sessions, $logger, $clock, $this->createMock(GroupSessionRevoker::class), $this->deletes($client, $logger));

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
			's.ok' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
			's.refused' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
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
			's.refused' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
		]);
		$client->method('deleteSession')->willThrowException(new EtherpadClientException('internal error'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('info');
		$logger->expects(self::exactly(2))->method('warning');

		self::assertSame(0, $this->revoker($client, logger: $logger)->revokeAll('alice'));
	}

	/**
	 * An index entry Etherpad cannot describe cannot be revoked either —
	 * deleteSession answers that it does not exist — so it belongs in the
	 * number that says a logout did not finish. The client counts them; the
	 * revoke must not drop the count on the way in.
	 */
	public function testCountsIndexEntriesItCannotClassify(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturnCallback(
			static function (string $authorId, ?int $timeout = null, ?int &$unreadable = null): array {
				$unreadable = 3;
				return ['s.live' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600]];
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
		self::assertSame(3, $reported, 'the entries it could not read are left behind too');
	}

	/**
	 * The pads of files leaving Files take their groups' live sessions
	 * along, each group asked once, within one budget. A group that holds
	 * other pads too keeps them - a legacy file may name someone else's -
	 * and a group gone already has none left. A public pad has no group.
	 * A group without a live session is not asked what it holds.
	 */
	public function testThePadsOfDeletedFilesTakeTheirGroupsSessions(): void {
		$client = $this->createMock(EtherpadClient::class);
		$padsAsked = [];
		$client->method('listPads')->willReturnCallback(static function (string $group) use (&$padsAsked): array {
			$padsAsked[] = $group;
			return match ($group) {
				'g.AAAAAAAAAAAAAAAA' => ['g.AAAAAAAAAAAAAAAA$one'],
				'g.BBBBBBBBBBBBBBBB' => [],
				'g.CCCCCCCCCCCCCCCC' => ['g.CCCCCCCCCCCCCCCC$three', 'g.CCCCCCCCCCCCCCCC$someone-elses'],
				default => throw new EtherpadClientException('groupID does not exist'),
			};
		});
		$listed = [];
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group) use (&$listed): array {
			$listed[] = $group;
			$old = ['s.old.' . $group => ['groupID' => $group, 'validUntil' => FixedClock::NOW - 3600]];
			return $group === 'g.EEEEEEEEEEEEEEEE' ? $old : $old + ['s.' . $group => ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600]];
		});
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			$removed[] = $id;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$queued = [];

		$count = $this->revoker($client, logger: $logger, groupSessions: $this->queueRecorder($queued))->revokeForPads([
			'g.AAAAAAAAAAAAAAAA$one',
			'g.AAAAAAAAAAAAAAAA$one',
			'g.BBBBBBBBBBBBBBBB$two',
			'g.CCCCCCCCCCCCCCCC$three',
			'g.DDDDDDDDDDDDDDDD$gone',
			'g.EEEEEEEEEEEEEEEE$quiet',
			'nc-public',
		]);

		self::assertSame(2, $count);
		self::assertSame(['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB', 'g.CCCCCCCCCCCCCCCC', 'g.DDDDDDDDDDDDDDDD', 'g.EEEEEEEEEEEEEEEE'], $listed);
		self::assertSame(['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB', 'g.CCCCCCCCCCCCCCCC', 'g.DDDDDDDDDDDDDDDD'], $padsAsked, 'not a group without a live session');
		self::assertSame(['s.g.AAAAAAAAAAAAAAAA', 's.g.BBBBBBBBBBBBBBBB'], $removed);
		// Every group but the one gone goes to the background job, which
		// takes what an open under way makes after the listing here, and
		// judges C by where its other pad's file is.
		self::assertSame(['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB', 'g.CCCCCCCCCCCCCCCC', 'g.EEEEEEEEEEEEEEEE'], $queued);
	}

	/**
	 * A group that cannot be asked is a warning, and the others go on; a
	 * budget spent leaves the groups not yet asked, with a line that says
	 * how many. Each of them goes to the background job.
	 */
	public function testAGroupThatCannotBeAskedGoesToTheBackground(): void {
		$client = $this->createMock(EtherpadClient::class);
		$clock = new FixedClock();
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group) use ($clock): array {
			if ($group === 'g.AAAAAAAAAAAAAAAA') {
				throw new EtherpadClientException('Connection timed out');
			}
			$clock->advance(2);
			return [];
		});
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$lines): void {
			$lines[] = [$message, $context['groupId'] ?? $context['groupsLeft'] ?? null];
		});
		$queued = [];
		$revoker = new PadSessionRevoker($client, $this->createMock(PadSessionService::class), $logger, $clock, $this->queueRecorder($queued), $this->deletes($client, $logger));

		$revoker->revokeForPads(['g.AAAAAAAAAAAAAAAA$a', 'g.BBBBBBBBBBBBBBBB$b', 'g.CCCCCCCCCCCCCCCC$c', 'g.DDDDDDDDDDDDDDDD$d']);

		self::assertSame([
			['Could not tell which Etherpad sessions to revoke; a background job tries again.', 'g.AAAAAAAAAAAAAAAA'],
			// B's sessions took the rest: C's and D's go unasked.
			['Not every group could be asked for its Etherpad sessions to revoke; a background job takes them.', 2],
		], $lines);
		self::assertSame(['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB', 'g.CCCCCCCCCCCCCCCC', 'g.DDDDDDDDDDDDDDDD'], $queued);
	}

	/**
	 * Each group's sessions go before the next group is asked. With every
	 * call taking 0.3 s, listing three groups first took the budget, and
	 * no session went; now the first group's does. The second group's
	 * sessions are listed, and left to the background job with the third,
	 * which is not asked.
	 */
	public function testASlowPadServerStillRevokesTheFirstGroupsSessions(): void {
		$clock = new FixedClock();
		$slow = static function () use ($clock): void {
			$clock->advanceMicros(300_000);
		};
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturnCallback(static function (string $group) use ($slow): array {
			$slow();
			return [$group . '$pad'];
		});
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group) use ($slow): array {
			$slow();
			return ['s.' . $group => ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600]];
		});
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use ($slow, &$removed): void {
			$slow();
			$removed[] = $id;
		});
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(static function (string $message, array $context) use (&$lines): void {
				$lines[] = [$message, $context['count'] ?? $context['groupsLeft'] ?? null, $context['leftToTheJob'] ?? null];
			});
		}
		$queued = [];
		$revoker = new PadSessionRevoker($client, $this->createMock(PadSessionService::class), $logger, $clock, $this->queueRecorder($queued), $this->deletes($client, $logger));

		$count = $revoker->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad', 'g.BBBBBBBBBBBBBBBB$pad', 'g.CCCCCCCCCCCCCCCC$pad']);

		self::assertSame(1, $count);
		self::assertSame(['s.g.AAAAAAAAAAAAAAAA'], $removed);
		self::assertSame([
			['Not every group could be asked for its Etherpad sessions to revoke; a background job takes them.', 1, null],
			['Revoked Etherpad sessions.', 1, 1],
		], $lines);
		self::assertSame(['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB', 'g.CCCCCCCCCCCCCCCC'], $queued);
	}

	/**
	 * A legacy group whose pads all leave together - two Ownpad files in a
	 * folder deleted - holds nothing that stays: its sessions go. One that
	 * keeps a pad of someone else's keeps them.
	 */
	public function testAGroupWhosePadsAllLeaveLosesItsSessions(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static fn (string $group): array => ['s.' . $group => ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600]]);
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$a', $group . '$b']);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			$removed[] = $id;
		});

		$queued = [];

		$this->revoker($client, groupSessions: $this->queueRecorder($queued))->revokeForPads(['g.AAAAAAAAAAAAAAAA$a', 'g.AAAAAAAAAAAAAAAA$b', 'g.BBBBBBBBBBBBBBBB$a']);

		$this->assertSame(['s.g.AAAAAAAAAAAAAAAA'], $removed);
		$this->assertSame(['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB'], $queued, 'B\'s other pad is the job\'s to judge');
	}

	/**
	 * Every open makes a session, so a pad opened often holds more than a
	 * cookie can: a delete takes up to a hundred, those that expire last
	 * first - most often whoever is at it now.
	 */
	public function testADeleteTakesTheSessionsThatExpireLastFirst(): void {
		$sessions = [];
		for ($i = 1; $i <= 120; $i++) {
			$sessions['s.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + $i];
		}
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturn($sessions);
		$client->method('listPads')->willReturn(['g.AAAAAAAAAAAAAAAA$pad']);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			$removed[] = $id;
		});

		$queued = [];

		$count = $this->revoker($client, groupSessions: $this->queueRecorder($queued))->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad']);

		self::assertSame(100, $count);
		self::assertSame(array_map(static fn (int $i): string => 's.' . $i, range(120, 21)), $removed);
		self::assertSame(['g.AAAAAAAAAAAAAAAA'], $queued, 'the oldest twenty to the background job');
	}

	/**
	 * A delete Etherpad refused leaves its session as live as one the
	 * budget never reached: the job takes it. An entry Etherpad lists and
	 * cannot describe no job can delete: it is left to expire.
	 */
	public function testARefusedDeleteIsLeftToTheJobAndAnUnreadableEntryToExpire(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group, ?int $timeout = null, ?int &$unreadable = null): array {
			$unreadable = 1;
			return ['s.' . $group => ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600]];
		});
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$pad']);
		$client->method('deleteSession')->willReturnCallback(static function (string $id): void {
			if ($id === 's.g.AAAAAAAAAAAAAAAA') {
				throw new EtherpadClientException('Etherpad answered 500');
			}
		});
		$info = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(static function (string $message, array $context) use (&$info): void {
			$info[] = [$context['count'], $context['leftToTheJob'], $context['groupsToTheJob'], $context['leftToExpire']];
		});

		$this->revoker($client, logger: $logger)->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad', 'g.BBBBBBBBBBBBBBBB$pad']);

		self::assertSame([[1, 1, 2, 2]], $info);
	}

	public function testGroupsPastTheCeilingGoToTheBackgroundUnasked(): void {
		$sessions = [];
		for ($i = 1; $i <= 120; $i++) {
			$sessions['s.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + $i];
		}
		$client = $this->createMock(EtherpadClient::class);
		$listed = [];
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group) use ($sessions, &$listed): array {
			$listed[] = $group;
			return $sessions;
		});
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$pad']);
		$queued = [];

		$count = $this->revoker($client, groupSessions: $this->queueRecorder($queued))->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad', 'g.BBBBBBBBBBBBBBBB$pad']);

		self::assertSame(100, $count);
		self::assertSame(['g.AAAAAAAAAAAAAAAA'], $listed, 'B is not asked');
		self::assertSame(['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB'], $queued);
	}

	/**
	 * One ceiling of attempts across every group a delete takes: the
	 * second group gets what the first left of it, the rest goes to the
	 * background job.
	 */
	public function testOneCeilingSpansEveryGroupOfADelete(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group): array {
			$sessions = [];
			for ($i = 1; $i <= 60; $i++) {
				$sessions['s.' . $group . $i] = ['groupID' => $group, 'validUntil' => FixedClock::NOW + $i];
			}
			return $sessions;
		});
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$pad']);
		$client->expects(self::exactly(100))->method('deleteSession');
		$queued = [];

		self::assertSame(100, $this->revoker($client, groupSessions: $this->queueRecorder($queued))->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad', 'g.BBBBBBBBBBBBBBBB$pad']));
		self::assertSame(['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB'], $queued, 'the rest to the background job');
	}

	/**
	 * The ceiling counts attempts across the groups: the first group's
	 * refusals and sessions already gone count as surely as its deletes.
	 */
	public function testOneCeilingCountsEveryAttemptAcrossTheGroups(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group): array {
			$sessions = [];
			for ($i = 1; $i <= 60; $i++) {
				$sessions['s.' . $group . '.' . $i] = ['groupID' => $group, 'validUntil' => FixedClock::NOW + 1000 - $i];
			}
			return $sessions;
		});
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$pad']);
		$calls = 0;
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$calls): void {
			$calls++;
			// The first group: 40 deleted, then 10 refused, then 10 gone.
			if (str_starts_with($id, 's.g.AAAAAAAAAAAAAAAA.')) {
				$n = (int)substr($id, strrpos($id, '.') + 1);
				if ($n > 50) {
					throw new EtherpadRefusedException('Etherpad API error (deleteSession): sessionID does not exist');
				}
				if ($n > 40) {
					throw new EtherpadRefusedException('Etherpad API error (deleteSession): internal error');
				}
			}
		});

		self::assertSame(80, $this->revoker($client)->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad', 'g.BBBBBBBBBBBBBBBB$pad']));
		self::assertSame(100, $calls);
	}

	/**
	 * The limits on refusals hold across the groups of a delete, not for
	 * each: twenty in a row end it, and the groups not yet asked go to the
	 * background job.
	 */
	public function testTwentyRefusalsInARowEndADeleteAcrossItsGroups(): void {
		$groups = ['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB', 'g.CCCCCCCCCCCCCCCC', 'g.DDDDDDDDDDDDDDDD', 'g.EEEEEEEEEEEEEEEE'];
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group): array {
			$sessions = [];
			for ($i = 1; $i <= 10; $i++) {
				$sessions['s.' . $group . '.' . $i] = ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600];
			}
			return $sessions;
		});
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$pad']);
		$client->expects(self::exactly(20))->method('deleteSession')->willThrowException(new EtherpadRefusedException('Etherpad API error (deleteSession): internal error'));
		$queued = [];

		self::assertSame(0, $this->revoker($client, groupSessions: $this->queueRecorder($queued))->revokeForPads(array_map(static fn (string $group): string => $group . '$pad', $groups)));
		self::assertSame($groups, $queued);
	}

	/** And fifty in all, scattered among deletes across the groups. */
	public function testFiftyRefusalsInAllEndADeleteAcrossItsGroups(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group): array {
			$sessions = [];
			for ($i = 1; $i <= 60; $i++) {
				$sessions['s.' . $group . '.' . $i] = ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600];
			}
			return $sessions;
		});
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$pad']);
		$calls = 0;
		// Two refused, one deleted, and again.
		$client->method('deleteSession')->willReturnCallback(static function () use (&$calls): void {
			$calls++;
			if ($calls % 3 !== 0) {
				throw new EtherpadRefusedException('Etherpad API error (deleteSession): internal error');
			}
		});

		self::assertSame(24, $this->revoker($client)->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad', 'g.BBBBBBBBBBBBBBBB$pad']));
		self::assertSame(74, $calls);
	}

	/**
	 * Listings that fail without an answer count towards an outage: five
	 * end the asking, and the groups after them go to the job unasked.
	 */
	public function testListingsWithoutAnAnswerEndTheAsking(): void {
		$groups = array_map(static fn (string $letter): string => 'g.' . str_repeat($letter, 16), range('A', 'J'));
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::exactly(5))->method('listSessionsOfGroup')->willThrowException(new EtherpadClientException('Connection refused'));
		$queued = [];

		self::assertSame(0, $this->revoker($client, groupSessions: $this->queueRecorder($queued))->revokeForPads(array_map(static fn (string $group): string => $group . '$pad', $groups)));
		self::assertSame($groups, $queued);
	}

	/**
	 * A group whose listing went through starts the count of failures
	 * again: a 502 now and then among the listings leaves no group unasked.
	 */
	public function testAFailedListingNowAndThenLeavesNoGroupUnasked(): void {
		$groups = array_map(static fn (int $i): string => sprintf('g.GROUP%011d', $i), range(1, 20));
		$listed = 0;
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function () use (&$listed): array {
			$listed++;
			if ($listed % 2 === 0) {
				throw new EtherpadClientException('Etherpad API HTTP error (502)');
			}
			return [];
		});

		$this->revoker($client)->revokeForPads(array_map(static fn (string $group): string => $group . '$pad', $groups));

		self::assertSame(20, $listed);
	}

	/**
	 * A listing Etherpad refuses - a code other than 0 with HTTP 2xx - is
	 * no outage: it ends no asking.
	 */
	public function testARefusedListingIsNoOutage(): void {
		$groups = array_map(static fn (int $i): string => sprintf('g.GROUP%011d', $i), range(1, 10));
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::exactly(10))->method('listSessionsOfGroup')->willThrowException(new EtherpadRefusedException('Etherpad API error (listSessionsOfGroup): refused'));

		$this->revoker($client)->revokeForPads(array_map(static fn (string $group): string => $group . '$pad', $groups));
	}

	/**
	 * Nor does a refused listing start the count of failures again: only a
	 * group gone through does. Four failures, a refusal and one more end
	 * the asking.
	 */
	public function testARefusedListingStartsNoCountAgain(): void {
		$groups = array_map(static fn (int $i): string => sprintf('g.GROUP%011d', $i), range(1, 10));
		$listed = 0;
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function () use (&$listed): array {
			$listed++;
			throw $listed === 5
				? new EtherpadRefusedException('Etherpad API error (listSessionsOfGroup): refused')
				: new EtherpadClientException('Etherpad API HTTP error (502)');
		});

		$this->revoker($client)->revokeForPads(array_map(static fn (string $group): string => $group . '$pad', $groups));

		self::assertSame(6, $listed);
	}

	/**
	 * A group is through only when its pads are listed too: a proxy that
	 * passes the session listings and fails the pad listings ends the
	 * asking after five groups.
	 */
	public function testAGroupWhosePadsCannotBeListedIsNotGoneThrough(): void {
		$groups = array_map(static fn (int $i): string => sprintf('g.GROUP%011d', $i), range(1, 10));
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::exactly(5))->method('listSessionsOfGroup')->willReturnCallback(static fn (string $group): array => ['s.' . $group => ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600]]);
		$client->method('listPads')->willThrowException(new EtherpadClientException('Etherpad API HTTP error (502)'));
		$client->expects(self::never())->method('deleteSession');
		$queued = [];

		$this->revoker($client, groupSessions: $this->queueRecorder($queued))->revokeForPads(array_map(static fn (string $group): string => $group . '$pad', $groups));

		self::assertSame($groups, $queued);
	}

	/**
	 * A group whose pads were listed is through, though it holds other pads
	 * too and nothing is deleted in it: failures between such groups leave
	 * none unasked.
	 */
	public function testAGroupHoldingOtherPadsIsGoneThroughToo(): void {
		$groups = array_map(static fn (int $i): string => sprintf('g.GROUP%011d', $i), range(1, 10));
		$listed = 0;
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group) use (&$listed): array {
			$listed++;
			if ($listed % 2 === 1) {
				throw new EtherpadClientException('Etherpad API HTTP error (502)');
			}
			return ['s.' . $group => ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600]];
		});
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$pad', $group . '$other']);
		$client->expects(self::never())->method('deleteSession');

		$this->revoker($client)->revokeForPads(array_map(static fn (string $group): string => $group . '$pad', $groups));

		self::assertSame(10, $listed);
	}

	/**
	 * A listing that takes the budget leaves the group's sessions to the
	 * background job, without asking which pads it holds.
	 */
	public function testAListingThatTakesTheBudgetLeavesTheGroupToTheJob(): void {
		$clock = new FixedClock();
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function () use ($clock): array {
			$clock->advance(2);
			return [
				's.1' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
				's.2' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
			];
		});
		$client->expects(self::never())->method('listPads');
		$client->expects(self::never())->method('deleteSession');
		$left = null;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$left): void {
			$left = $context['leftToTheJob'] ?? $left;
		});
		$queued = [];
		$revoker = new PadSessionRevoker($client, $this->createMock(PadSessionService::class), $logger, $clock, $this->queueRecorder($queued), $this->deletes($client, $logger));

		self::assertSame(0, $revoker->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad']));
		self::assertSame(2, $left);
		self::assertSame(['g.AAAAAAAAAAAAAAAA'], $queued);
	}

	/** A session already gone is not left behind: it is what a revoke asks for. */
	public function testASessionAlreadyGoneIsNotLeftBehind(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.gone' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
			's.live' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
		]);
		$client->method('deleteSession')->willReturnCallback(static function (string $id): void {
			if ($id === 's.gone') {
				throw new EtherpadRefusedException('Etherpad API error (deleteSession): sessionID does not exist');
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('Revoked Etherpad sessions.', self::callback(static fn (array $context): bool => $context['count'] === 1 && $context['leftToExpire'] === 0));

		self::assertSame(1, $this->revoker($client, logger: $logger)->revokeAll('alice'));
	}

	/**
	 * The line says where the rest went: the sessions left in groups the
	 * job takes, how many groups it takes - one it never listed among
	 * them - and what Etherpad lists and cannot describe, which expires.
	 */
	public function testSaysWhatGoesToTheJobAndWhatExpires(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group, ?int $timeout = null, ?int &$unreadable = null): array {
			if ($group === 'g.AAAAAAAAAAAAAAAA') {
				throw new EtherpadClientException('Connection timed out');
			}
			$unreadable = 2;
			return ['s.b' => ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600]];
		});
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$pad']);
		$info = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(static function (string $message, array $context) use (&$info): void {
			$info[] = [$message, $context['count'], $context['leftToTheJob'], $context['groupsToTheJob'], $context['leftToExpire']];
		});
		$queued = [];

		$this->revoker($client, logger: $logger, groupSessions: $this->queueRecorder($queued))->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad', 'g.BBBBBBBBBBBBBBBB$pad']);

		self::assertSame([['Revoked Etherpad sessions.', 1, 0, 2, 2]], $info);
		self::assertSame(['g.AAAAAAAAAAAAAAAA', 'g.BBBBBBBBBBBBBBBB'], $queued);
	}

	/**
	 * A group that cannot be queued goes to no job: what it left expires,
	 * and the line says so - not that a job takes it.
	 */
	public function testAGroupThatCannotBeQueuedIsNotSaidToGoToTheJob(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static fn (string $group): array => ['s.' . $group => ['groupID' => $group, 'validUntil' => FixedClock::NOW + 3600]]);
		$client->method('listPads')->willReturnCallback(static fn (string $group): array => [$group . '$pad', $group . '$other']);
		$groupSessions = $this->createMock(GroupSessionRevoker::class);
		$groupSessions->method('queue')->willReturnCallback(static fn (string $group): bool => $group === 'g.BBBBBBBBBBBBBBBB');
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$lines): void {
			if (array_key_exists('leftToTheJob', $context)) {
				$lines[] = [$message, $context['leftToTheJob'], $context['groupsToTheJob'], $context['leftToExpire']];
			}
		});

		$this->revoker($client, logger: $logger, groupSessions: $groupSessions)->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad', 'g.BBBBBBBBBBBBBBBB$pad']);

		self::assertSame([['Revoked no Etherpad sessions yet; a background job takes them.', 1, 1, 1]], $lines);
	}

	/**
	 * A group with no live session is no warning: nothing was left, though
	 * it goes to the job like every other.
	 */
	public function testAGroupWithoutALiveSessionIsNoWarning(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturn([]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::never())->method('info');
		$queued = [];

		$this->revoker($client, logger: $logger, groupSessions: $this->queueRecorder($queued))->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad']);

		self::assertSame(['g.AAAAAAAAAAAAAAAA'], $queued);
	}

	/**
	 * Only entries Etherpad cannot describe left: nothing for the job, and
	 * the line says they expire.
	 */
	public function testOnlyUnreadableEntriesLeftExpire(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function (string $group, ?int $timeout = null, ?int &$unreadable = null): array {
			$unreadable = 3;
			return [];
		});
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$lines): void {
			$lines[] = [$message, $context['leftToTheJob'], $context['leftToExpire'], $context['groupsToTheJob']];
		});

		$this->revoker($client, logger: $logger)->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad']);

		self::assertSame([['Revoked no Etherpad sessions; they will expire on their own.', 0, 3, 1]], $lines);
	}

	/**
	 * A group that holds other pads too loses no session here: whether it
	 * should - every other pad's file in a trash too - is the background
	 * job's to judge, which costs the request no lookup.
	 */
	public function testAGroupHoldingOtherPadsIsTheJobsToJudge(): void {
		$client = $this->createMock(EtherpadClient::class);
		// A delete reads its listing whole, as a logout does.
		$client->method('listSessionsOfGroup')->with('g.AAAAAAAAAAAAAAAA', self::anything(), self::anything(), self::isNull())->willReturn(['s.a' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600]]);
		$client->method('listPads')->willReturn(['g.AAAAAAAAAAAAAAAA$a', 'g.AAAAAAAAAAAAAAAA$trashed-earlier']);
		$client->expects(self::never())->method('deleteSession');
		$queued = [];
		// Listed, and the job's to take: counted as left to it.
		$left = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$left): void {
			if (array_key_exists('leftToTheJob', $context)) {
				$left[] = [$message, $context['leftToTheJob'], $context['leftToExpire']];
			}
		});

		$this->revoker($client, logger: $logger, groupSessions: $this->queueRecorder($queued))->revokeForPads(['g.AAAAAAAAAAAAAAAA$a']);

		self::assertSame(['g.AAAAAAAAAAAAAAAA'], $queued);
		self::assertSame([['Revoked no Etherpad sessions yet; a background job takes them.', 1, 0]], $left);
	}

	/**
	 * Sessions listed before the group could be asked what else it holds
	 * are left as surely as those never listed, and the line counts them.
	 */
	public function testSessionsListedBeforeAFailureAreCountedAsLeftToTheJob(): void {
		$sessions = [];
		for ($i = 1; $i <= 40; $i++) {
			$sessions['s.' . $i] = ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + $i];
		}
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfGroup')->willReturn($sessions);
		$client->method('listPads')->willThrowException(new EtherpadClientException('Connection timed out'));
		$left = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$left): void {
			if (array_key_exists('leftToTheJob', $context)) {
				$left[] = [$message, $context['leftToTheJob'], $context['groupsToTheJob']];
			}
		});

		$this->revoker($client, logger: $logger)->revokeForPads(['g.AAAAAAAAAAAAAAAA$pad']);

		self::assertSame([['Revoked no Etherpad sessions yet; a background job takes them.', 40, 1]], $left);
	}

	/**
	 * A GroupSessionRevoker that records the groups queued into $queued.
	 *
	 * @param list<string> $queued
	 */
	private function queueRecorder(array &$queued): GroupSessionRevoker {
		$groupSessions = $this->createMock(GroupSessionRevoker::class);
		$groupSessions->method('queue')->willReturnCallback(static function (string $groupId) use (&$queued): bool {
			$queued[] = $groupId;
			return true;
		});
		return $groupSessions;
	}

	private function revoker(
		EtherpadClient $client,
		string $author = self::AUTHOR,
		?LoggerInterface $logger = null,
		array $carriedIds = [],
		?GroupSessionRevoker $groupSessions = null,
	): PadSessionRevoker {
		$queued = [];
		$sessions = $this->createMock(PadSessionService::class);
		$sessions->method('cachedAuthorId')->willReturn($author);
		$sessions->method('carriedSessionIds')->willReturn($carriedIds);

		$logger ??= $this->createMock(LoggerInterface::class);
		return new PadSessionRevoker(
			$client,
			$sessions,
			$logger,
			new FixedClock(),
			$groupSessions ?? $this->queueRecorder($queued),
			$this->deletes($client, $logger),
		);
	}

	/** The real delete loop: what it counts and stops at is what a revoke reports. */
	private function deletes(EtherpadClient $client, LoggerInterface $logger): SessionDeletes {
		return new SessionDeletes($client, new ManagedPadLifecycle($client, $logger), $logger);
	}
}
