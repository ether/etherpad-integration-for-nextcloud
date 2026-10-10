<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredGroupSessionsJob;
use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredSessionsJob;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExpiredSessionCollector;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\SessionDeletes;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;

/**
 * Etherpad never removes an expired session, and listSessionsOfAuthor
 * walks the author's whole index one awaited lookup at a time. Left alone
 * the list grows with every pad this user has ever opened, until the
 * listing alone can spend the revoke budget — at which point a logout
 * revokes nothing.
 */
class ExpiredSessionCollectorTest extends TestCase {
	private const AUTHOR = 'a.author';
	private const GROUP = 'g.AAAAAAAAAAAAAAAA';

	/**
	 * Long enough past expiry that both clocks must agree. A session that
	 * ran out a minute ago is deliberately not collected.
	 */
	private static function expired(): array {
		return ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW - 3600];
	}

	/** @return array{groupID:string,validUntil:int} */
	private static function justExpired(): array {
		return ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW - 60];
	}

	/** @return array{groupID:string,validUntil:int} */
	private static function live(): array {
		return ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600];
	}

	/** @return array<string,array{groupID:string,validUntil:int}> */
	private static function expiredSessions(int $count, string $prefix = 's.old'): array {
		$sessions = [];
		for ($i = 0; $i < $count; $i++) {
			$sessions[$prefix . $i] = self::expired();
		}
		return $sessions;
	}

	public function testQueuesASweepForTheAuthorThatJustOpenedAPad(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(false);
		$jobList->expects(self::once())->method('add')->with(
			CollectExpiredSessionsJob::class,
			['authorId' => self::AUTHOR],
		);

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteAuthor(self::AUTHOR);
	}

	/**
	 * Without asking whether there is anything to collect. Finding that out
	 * is the listing, and the listing is the call this class exists to keep
	 * out of a request.
	 */
	public function testAsksThePadServerNothingWhileQueueing(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('listSessionsOfAuthor');

		$this->collector($client)->noteAuthor(self::AUTHOR);
	}

	/**
	 * A public link's sessions are noted by the group it opened: its
	 * visitors share the group, whatever author each opens as, and its uid
	 * holds the share token, which no job argument may.
	 */
	public function testQueuesASweepForTheGroupAPublicLinkOpened(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(false);
		$jobList->expects(self::once())->method('add')->with(
			CollectExpiredGroupSessionsJob::class,
			['groupId' => self::GROUP],
		);

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteGroup(self::GROUP);
	}

	/** A group's sweep waiting, its retry too, is not queued twice; no group, no sweep. */
	public function testQueuesAGroupOnlyOnce(): void {
		foreach ([['groupId' => self::GROUP], ['groupId' => self::GROUP, 'attempt' => 2]] as $waiting) {
			$jobList = $this->createMock(IJobList::class);
			$jobList->method('has')->willReturnCallback(static fn (string $job, mixed $argument): bool => $job === CollectExpiredGroupSessionsJob::class && $argument === $waiting);
			$jobList->expects(self::never())->method('add');

			$this->collector($this->createMock(EtherpadClient::class), $jobList)->noteGroup(self::GROUP);
		}
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');
		$this->collector($this->createMock(EtherpadClient::class), $jobList)->noteGroup('');
	}

	/**
	 * A group's sweep takes the expired sessions of every author in it -
	 * a link's visitors, and a signed-in user's - and leaves the live
	 * ones, coming back as the earliest falls due.
	 */
	public function testCollectsAGroupsExpiredSessions(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('listSessionsOfAuthor');
		$client->method('listSessionsOfGroup')->with(self::GROUP)->willReturn([
			's.visitor' => self::expired(),
			's.live' => self::live(),
			's.member' => self::expired(),
		]);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			$removed[] = $id;
		});

		$result = $this->collector($client)->collectGroup(self::GROUP);

		self::assertSame(['s.visitor', 's.member'], $removed);
		self::assertSame(['deleted' => 2, 'remaining' => 0, 'retry' => false, 'nextDueAt' => FixedClock::NOW + 3600 + EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS], $result);
	}

	/**
	 * A listing too long to read, or too slow while Etherpad answers
	 * otherwise, is read no sooner by a run: the sweep is parked for a day,
	 * with a line, and reads it only then. The cap is the sweep's to ask for.
	 */
	public function testAListingThatCannotBeReadParksTheSweepForADay(): void {
		$failures = [
			'too long' => new EtherpadTooLargeException('Etherpad API response exceeds 4194304 bytes.'),
			'too slow' => new EtherpadClientException('Etherpad API request failed: listSessionsOfGroup'),
		];
		$listings = [
			'a group\'s' => ['listSessionsOfGroup', self::GROUP, 'groupId', static fn (ExpiredSessionCollector $c): array => $c->collectGroup(self::GROUP)],
			'an author\'s' => ['listSessionsOfAuthor', self::AUTHOR, 'authorId', static fn (ExpiredSessionCollector $c): array => $c->collect(self::AUTHOR)],
		];
		foreach ($listings as $listing => [$method, $id, $key, $collect]) {
			foreach ($failures as $case => $failure) {
				$client = $this->createMock(EtherpadClient::class);
				$client->expects(self::once())->method($method)
					->with($id, self::anything(), self::anything(), EtherpadClient::SESSION_LISTING_MAX_BYTES)
					->willThrowException($failure);
				$client->expects(self::never())->method('deleteSession');
				$logger = $this->createMock(LoggerInterface::class);
				$logger->expects(self::once())->method('warning')->with(
					'The Etherpad sessions to collect are too many to list in a run; asked again in a day.',
					self::callback(static fn (array $context): bool => $context[$key] === $id),
				);

				self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => FixedClock::NOW + 86400], $collect($this->collector($client, logger: $logger)), $listing . ', ' . $case);
			}
		}
	}

	/**
	 * An author or a group Etherpad no longer has holds nothing to
	 * collect: the sweep ends, said at debug, with nothing deleted.
	 */
	public function testAnAuthorOrGroupEtherpadNoLongerHasEndsTheSweep(): void {
		$cases = [
			'an author' => ['listSessionsOfAuthor', 'authorID does not exist', static fn (ExpiredSessionCollector $c): array => $c->collect(self::AUTHOR), 'authorId', self::AUTHOR],
			'a group' => ['listSessionsOfGroup', 'groupID does not exist', static fn (ExpiredSessionCollector $c): array => $c->collectGroup(self::GROUP), 'groupId', self::GROUP],
		];
		foreach ($cases as $case => [$method, $answer, $collect, $key, $id]) {
			$client = $this->createMock(EtherpadClient::class);
			$client->method($method)->willThrowException(new EtherpadRefusedException('Etherpad API error (' . $method . '): ' . $answer));
			$client->expects(self::never())->method('deleteSession');
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects(self::never())->method('warning');
			$logger->expects(self::once())->method('debug')->with(self::anything(), self::callback(static fn (array $context): bool => $context[$key] === $id));

			self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null], $collect($this->collector($client, logger: $logger)), $case);
		}
	}

	/** A listing Etherpad does not answer at all is tried again, and said under its group. */
	public function testAGroupUnlistedInAnOutageIsTriedAgain(): void {
		$down = $this->createMock(EtherpadClient::class);
		$down->method('listSessionsOfGroup')->willThrowException(new EtherpadClientException('Connection timed out'));
		$down->method('assertAnswering')->willThrowException(new EtherpadClientException('Connection timed out'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Could not list the Etherpad sessions to collect.',
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP && !isset($context['authorId'])),
		);
		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null], $this->collector($down, logger: $logger)->collectGroup(self::GROUP));
	}

	/** No author means nothing was ever issued under one. */
	public function testQueuesNothingWithoutAnAuthor(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteAuthor('');
	}

	/**
	 * A second note would not be ignored: Nextcloud updates the row and
	 * clears last_run and reserved_at, so a sweep deliberately scheduled a
	 * minute out would be released by the next pad open.
	 */
	public function testDoesNotTouchANoteThatIsAlreadyThere(): void {
		$jobList = $this->createMock(IJobList::class);
		// The plain row only, no retry beside it.
		$jobList->method('has')->willReturnCallback(
			static fn (string $job, mixed $argument): bool => is_array($argument) && !isset($argument['attempt'])
		);
		$jobList->expects(self::never())->method('add');

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteAuthor(self::AUTHOR);
	}

	/**
	 * A retry that is deliberately waiting counts as queued. It carries its
	 * attempt in the argument so an open cannot reset its backoff, and the
	 * job list matches arguments exactly — so asking only about the plain
	 * form would add a second row that the next cron runs at once.
	 */
	public function testSeesARetryThatIsAlreadyWaiting(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturnCallback(
			static fn (string $job, mixed $argument): bool => is_array($argument)
				&& ($argument['attempt'] ?? 0) === 3
		);
		$jobList->expects(self::never())->method('add');

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteAuthor(self::AUTHOR);
	}

	/**
	 * Queueing is housekeeping, and housekeeping may not be why a pad fails
	 * to open. Both calls behind it touch Nextcloud's database from inside
	 * a request somebody is waiting on.
	 */
	public function testAnUnreachableJobTableDoesNotBreakTheOpen(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willThrowException(new \RuntimeException('Deadlock found'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$this->collector($this->createMock(EtherpadClient::class), $jobList, $logger)
			->noteAuthor(self::AUTHOR);
	}

	public function testDeletesTheExpiredOnesAndLeavesTheLiveOnesAlone(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.old' => self::expired(),
			's.live' => self::live(),
			's.older' => self::expired(),
		]);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id) use (&$removed): void {
				$removed[] = $id;
			}
		);

		$result = $this->collector($client)->collect(self::AUTHOR);

		self::assertSame(['s.old', 's.older'], $removed);
		self::assertSame(2, $result['deleted']);
		self::assertSame(0, $result['remaining']);
		self::assertFalse($result['retry']);
		self::assertNotNull(
			$result['nextDueAt'],
			'the live session it left alone is what the next run comes back for',
		);
	}

	/**
	 * Every delete carries the run's own timeout, not the client's.
	 *
	 * A deadline checked between calls says when the last one may start,
	 * never when it must end. With the client's standard timeout a delete
	 * begun just under the deadline runs far past it, and the budget stops
	 * describing the run at all — the number it advertises would be short
	 * by a whole timeout.
	 *
	 * The deadline is taken before the listing, so a slow listing comes out
	 * of the same budget rather than being added to it.
	 */
	public function testGivesEveryDeleteWhatIsLeftOfTheBudget(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(3));
		$timeouts = [];
		$client->expects(self::exactly(3))->method('deleteSession')->willReturnCallback(
			static function (string $id, ?int $timeoutSeconds = null) use (&$timeouts): void {
				$timeouts[] = $timeoutSeconds;
			}
		);

		$this->collector($client)->collect(self::AUTHOR);

		foreach ($timeouts as $timeout) {
			self::assertNotNull($timeout, 'a delete was issued with the client default');
			self::assertGreaterThanOrEqual(2, $timeout, 'never issue an already-dead timeout');
			self::assertLessThanOrEqual(
				15,
				$timeout,
				'housekeeping must not be more patient than the calls a user waits on',
			);
		}
	}

	/**
	 * A call that cannot finish inside the budget is not started.
	 *
	 * Checking only that the deadline has not passed lets the last delete
	 * begin with a fraction of a second left and then run for its whole
	 * timeout — the budget wrong by a timeout again, just a smaller one.
	 * The sessions it did not get to are the next run's business.
	 */
	public function testStartsNoCallItCannotFinishInTime(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(5));
		$client->expects(self::never())->method('deleteSession');

		$result = $this->collector($client, budgetSeconds: 0.3)->collect(self::AUTHOR);

		self::assertSame(['deleted' => 0, 'remaining' => 5, 'retry' => false, 'nextDueAt' => null], $result);
	}

	/**
	 * The listing is inside the budget too.
	 *
	 * It was the one call in the run that could ignore it, which is
	 * backwards: it is the expensive call this whole class exists because
	 * of, so a slow index could spend the run and leave no time to delete
	 * anything from it.
	 */
	public function testTheListingIsBoundedByTheRunsBudgetAsWell(): void {
		$client = $this->createMock(EtherpadClient::class);
		$seen = 'unset';
		$client->method('listSessionsOfAuthor')->willReturnCallback(
			static function (string $authorId, ?int $timeoutSeconds = null) use (&$seen): array {
				$seen = $timeoutSeconds;
				return [];
			}
		);

		$this->collector($client)->collect(self::AUTHOR);

		self::assertNotSame('unset', $seen);
		self::assertNotNull($seen, 'the listing went out with the client default');
		self::assertGreaterThanOrEqual(1, $seen);
		self::assertLessThanOrEqual(15, $seen);
	}

	/**
	 * A sweep that found nothing to do says when there will be.
	 *
	 * Otherwise the very next open queues another full walk of the index:
	 * a busy public link would have one behind every visitor, for as long
	 * as a session lives, over the one author they all share. The answer is
	 * already in the listing this run paid for.
	 */
	public function testSaysWhenTheEarliestSessionBecomesCollectable(): void {
		$soon = FixedClock::NOW + 600;
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.later' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => FixedClock::NOW + 3600],
			's.soon' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => $soon],
		]);
		$client->expects(self::never())->method('deleteSession');

		$result = $this->collector($client)->collect(self::AUTHOR);

		self::assertSame(0, $result['deleted']);
		self::assertSame($soon + 300, $result['nextDueAt'], 'the earliest one, plus the grace');
	}

	/** An author holding nothing has nothing to come back for. */
	public function testHasNoDueTimeWhenTheAuthorHoldsNothing(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([]);

		self::assertNull($this->collector($client)->collect(self::AUTHOR)['nextDueAt']);
	}

	/**
	 * An Etherpad that keeps index entries it cannot describe says so in
	 * the log, rather than letting a sweep look clean.
	 *
	 * Those keys cost a lookup on every listing and `deleteSession` will
	 * not take them, so collecting cannot shrink that part of the index —
	 * on such a server this whole feature is not the remedy it is here, and
	 * an admin should be able to find that out from the log rather than by
	 * reading the pad server's source.
	 */
	public function testSaysWhenTheIndexHoldsEntriesItCannotCollect(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturnCallback(
			static function (string $authorId, ?int $timeout = null, ?int &$unreadable = null): array {
				$unreadable = 7;
				return [];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with(self::stringContains('cannot describe'), self::anything());

		$this->collector($client, logger: $logger)->collect(self::AUTHOR);
	}

	/**
	 * The ceiling has to be reported honestly, because the job decides
	 * whether to come back from this number alone.
	 */
	public function testReportsWhatDidNotFitInOneRun(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(400));

		$result = $this->collector($client)->collect(self::AUTHOR);

		self::assertSame(250, $result['deleted']);
		self::assertSame(150, $result['remaining']);
	}

	/**
	 * A session somebody else removed in the meantime is the outcome asked
	 * for, so it counts as handled — otherwise the job would come back for
	 * work that no longer exists.
	 */
	public function testCountsAnAlreadyGoneSessionAsDone(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.old' => self::expired(),
			's.gone' => self::expired(),
		]);
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id): void {
				if ($id === 's.gone') {
					throw new EtherpadClientException('sessionID does not exist');
				}
			}
		);

		$result = $this->collector($client)->collect(self::AUTHOR);

		self::assertSame(1, $result['deleted']);
		self::assertSame(0, $result['remaining'], 'a session that is already gone is not left over');
	}

	/**
	 * The ceiling counts what was dealt with, not what this run deleted.
	 * An author whose backlog was already cleared by somebody else would
	 * otherwise never reach it and walk the whole index in one run — the
	 * ceiling would exist only for the happy path.
	 */
	public function testAnAlreadyClearedBacklogStillHitsTheCeiling(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(400));
		$calls = 0;
		$client->method('deleteSession')->willReturnCallback(
			static function () use (&$calls): void {
				$calls++;
				throw new EtherpadClientException('sessionID does not exist');
			}
		);

		$result = $this->collector($client)->collect(self::AUTHOR);

		self::assertSame(250, $calls, 'the run should stop at the ceiling, not walk the whole index');
		self::assertSame(['deleted' => 0, 'remaining' => 150, 'retry' => false, 'nextDueAt' => null], $result);
	}

	/**
	 * One session Etherpad will never delete must not shadow the rest.
	 *
	 * Stopping at the first refusal put that entry in front of everything
	 * behind it for good: the next run re-lists, meets it first, stops
	 * again, and the backlog never moves while every pad open re-queues the
	 * same doomed sweep. GoneFileSweep likewise goes on past a pad Etherpad
	 * refuses to delete, and tries it again an hour later.
	 */
	public function testAPoisonEntryDoesNotShadowTheOnesBehindIt(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.poison' => self::expired(),
			's.one' => self::expired(),
			's.two' => self::expired(),
		]);
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id): void {
				if ($id === 's.poison') {
					throw new EtherpadClientException('internal error');
				}
			}
		);

		$result = $this->collector($client)->collect(self::AUTHOR);

		self::assertSame(2, $result['deleted'], 'the entries behind the poison one should still go');
		self::assertSame(1, $result['remaining'], 'and the poison one is what is left');
		self::assertTrue($result['retry'], 'a refusal still asks for the backoff');
	}

	/**
	 * The other reading of a failure is that the server is down - it does
	 * not answer when asked whether it answers at all - and then there is
	 * nothing to be gained by asking two hundred more times.
	 */
	public function testGivesUpOnARunThatKeepsBeingRefused(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(100));
		$client->method('assertAnswering')->willThrowException(new EtherpadClientException('Connection timed out'));
		$calls = 0;
		$client->method('deleteSession')->willReturnCallback(
			static function () use (&$calls): void {
				$calls++;
				throw new EtherpadClientException('Connection timed out');
			}
		);

		$result = $this->collector($client)->collect(self::AUTHOR);

		self::assertSame(5, $calls, 'a run puts up with a handful of failures without an answer, not a hundred');
		self::assertSame(['deleted' => 0, 'remaining' => 100, 'retry' => true, 'nextDueAt' => null], $result);
	}


	/**
	 * Nextcloud computes validUntil; Etherpad judges it against its own
	 * clock. In the window where the two disagree, a session this side
	 * calls dead is one the pad server still grants — and deleting it
	 * closes a socket somebody is typing into. Nothing here is urgent
	 * enough to be worth that.
	 */
	public function testWaitsOutTheClockDifferenceBeforeDeleting(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.justnow' => self::justExpired(),
			's.longago' => self::expired(),
		]);
		$client->expects(self::once())->method('deleteSession')->with('s.longago');

		self::assertSame(1, $this->collector($client)->collect(self::AUTHOR)['deleted']);
	}

	/**
	 * A session id is the value of the `sessionID` cookie — the credential
	 * itself — and this branch is reached for sessions the pad server may
	 * still accept. A digest correlates the same entry across runs without
	 * writing the credential into a log that outlives it.
	 *
	 * The failure is raised from inside the call rather than handed to the
	 * mock ready-made: an exception built beforehand carries a stack that
	 * never entered this collector, so a leak through the frames would
	 * have nothing to leak from and the check would pass on nothing.
	 */
	public function testDoesNotWriteASessionIdIntoTheLog(): void {
		$sessionId = 's.secretsecret123';
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([$sessionId => self::expired()]);
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id): void {
				throw new EtherpadClientException('internal error while deleting ' . $id);
			}
		);

		$seen = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$seen): void {
				$seen[] = $context;
			}
		);

		$this->collector($client, logger: $logger)->collect(self::AUTHOR);

		self::assertNotSame([], $seen, 'the refusal should have been logged at all');
		foreach ($seen as $context) {
			// Every value, not just the ones this test thought to name: the
			// context is strings now, so encoding it hides nothing.
			self::assertStringNotContainsString(
				$sessionId,
				(string)json_encode($context),
				'the session id reached the log context',
			);
		}
		// And the entry is still worth having: the digest is what makes the
		// same session recognisable across runs.
		self::assertSame(
			substr(hash('sha256', $sessionId), 0, 12),
			$seen[0]['sessionRef'] ?? null,
		);
	}

	/** Nothing about collecting may take a pad server outage further. */
	public function testSurvivesAnUnreachablePadServer(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')
			->willThrowException(new EtherpadClientException('Connection timed out'));
		$client->method('assertAnswering')->willThrowException(new EtherpadClientException('Connection timed out'));

		self::assertSame(
			['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null],
			$this->collector($client)->collect(self::AUTHOR),
			'a failed listing is a retry, not an empty backlog',
		);
	}

	private function collector(
		EtherpadClient $client,
		?IJobList $jobList = null,
		?LoggerInterface $logger = null,
		?float $budgetSeconds = null,
	): ExpiredSessionCollector {
		$logger ??= $this->createMock(LoggerInterface::class);
		return new ExpiredSessionCollector(
			$client,
			$jobList ?? $this->createMock(IJobList::class),
			$logger,
			new FixedClock(),
			new SessionDeletes($client, new ManagedPadLifecycle($client, $logger), $logger),
			new ManagedPadLifecycle($client, $logger),
			...($budgetSeconds === null ? [] : [$budgetSeconds]),
		);
	}
}
