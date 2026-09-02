<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredSessionsJob;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExpiredSessionCollector;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Etherpad never removes an expired session, and listSessionsOfAuthor
 * walks the author's whole index one awaited lookup at a time. Left alone
 * the list grows with every pad this user has ever opened, until the
 * listing alone can spend the revoke budget — at which point a logout
 * revokes nothing.
 */
class ExpiredSessionCollectorTest extends TestCase {
	private const AUTHOR = 'a.author';

	/**
	 * Long enough past expiry that both clocks must agree. A session that
	 * ran out a minute ago is deliberately not collected.
	 */
	private static function expired(): array {
		return ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() - 3600];
	}

	/** @return array{groupID:string,validUntil:int} */
	private static function justExpired(): array {
		return ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() - 60];
	}

	/** @return array{groupID:string,validUntil:int} */
	private static function live(): array {
		return ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600];
	}

	/** @return array<string,array{groupID:string,validUntil:int}> */
	private static function expiredSessions(int $count, string $prefix = 's.old'): array {
		$sessions = [];
		for ($i = 0; $i < $count; $i++) {
			$sessions[$prefix . $i] = self::expired();
		}
		return $sessions;
	}

	public function testLeavesANoteOnceTheBacklogIsWorthSweeping(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(false);
		$jobList->expects(self::once())->method('add')->with(
			CollectExpiredSessionsJob::class,
			['uid' => 'alice', 'authorId' => self::AUTHOR],
		);

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, self::expiredSessions(50));
	}

	/**
	 * A day of ordinary use leaves a handful behind. Queueing a sweep for
	 * those would put a row in the job table on nearly every logout.
	 */
	public function testSaysNothingAboutAHandful(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, self::expiredSessions(49));
	}

	/**
	 * A retry that is deliberately waiting counts as queued.
	 *
	 * It carries its attempt in the argument so an open cannot reset its
	 * backoff, and the job list matches arguments exactly — so asking only
	 * about the plain form would miss it and add a second row that the next
	 * cron runs at once. That is the loop the backoff exists to prevent,
	 * reached from the other side.
	 */
	public function testSeesARetryThatIsAlreadyWaiting(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturnCallback(
			static fn (string $job, mixed $argument): bool => is_array($argument)
				&& ($argument['attempt'] ?? 0) === 3
		);
		$jobList->expects(self::never())->method('add');

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, self::expiredSessions(80));
	}

	/** Live sessions are not a backlog; they are the thing being protected. */
	public function testDoesNotCountLiveSessionsAsBacklog(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');

		$sessions = [];
		for ($i = 0; $i < 80; $i++) {
			$sessions['s.live' . $i] = self::live();
		}

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, $sessions);
	}

	/**
	 * A second note would not be ignored: Nextcloud updates the row and
	 * clears last_run and reserved_at, so a sweep this job deliberately put
	 * a minute out would be released by the next pad open.
	 */
	public function testDoesNotTouchANoteThatIsAlreadyThere(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(true);
		$jobList->expects(self::never())->method('add');

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, self::expiredSessions(80));
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

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

		self::assertSame(['s.old', 's.older'], $removed);
		self::assertSame(['deleted' => 2, 'remaining' => 0, 'retry' => false], $result);
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

		$this->collector($client)->collect('alice', self::AUTHOR);

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

		$result = $this->collector($client, budgetSeconds: 0.3)->collect('alice', self::AUTHOR);

		self::assertSame(['deleted' => 0, 'remaining' => 5, 'retry' => false], $result);
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

		$this->collector($client)->collect('alice', self::AUTHOR);

		self::assertNotSame('unset', $seen);
		self::assertNotNull($seen, 'the listing went out with the client default');
		self::assertGreaterThanOrEqual(1, $seen);
		self::assertLessThanOrEqual(15, $seen);
	}

	/**
	 * The ceiling has to be reported honestly, because the job decides
	 * whether to come back from this number alone.
	 */
	public function testReportsWhatDidNotFitInOneRun(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(400));

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

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

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

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

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

		self::assertSame(250, $calls, 'the run should stop at the ceiling, not walk the whole index');
		self::assertSame(['deleted' => 0, 'remaining' => 150, 'retry' => false], $result);
	}

	/**
	 * One session Etherpad will never delete must not shadow the rest.
	 *
	 * Stopping at the first refusal put that entry in front of everything
	 * behind it for good: the next run re-lists, meets it first, stops
	 * again, and the backlog never moves while every pad open re-queues the
	 * same doomed sweep. The sibling loop in PendingDeleteRetryService
	 * counts a failure and carries on for the same reason.
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

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

		self::assertSame(2, $result['deleted'], 'the entries behind the poison one should still go');
		self::assertSame(1, $result['remaining'], 'and the poison one is what is left');
		self::assertTrue($result['retry'], 'a refusal still asks for the backoff');
	}

	/**
	 * The other reading of a refusal is that the server is down, and then
	 * there is nothing to be gained by asking two hundred more times.
	 */
	public function testGivesUpOnARunThatKeepsBeingRefused(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(100));
		$calls = 0;
		$client->method('deleteSession')->willReturnCallback(
			static function () use (&$calls): void {
				$calls++;
				throw new EtherpadClientException('Connection timed out');
			}
		);

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

		self::assertSame(5, $calls, 'a run puts up with a handful of refusals, not a hundred');
		self::assertSame(['deleted' => 0, 'remaining' => 100, 'retry' => true], $result);
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

		self::assertSame(1, $this->collector($client)->collect('alice', self::AUTHOR)['deleted']);
	}

	/** And the same session does not count towards queueing a sweep either. */
	public function testDoesNotCountAFreshlyExpiredSessionAsBacklog(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(false);
		$jobList->expects(self::never())->method('add');

		$sessions = [];
		for ($i = 0; $i < 80; $i++) {
			$sessions['s.just' . $i] = self::justExpired();
		}

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, $sessions);
	}

	/**
	 * Queueing is housekeeping, and housekeeping may not be why a pad fails
	 * to open. Both calls behind this touch Nextcloud's database from
	 * inside a request somebody is waiting on.
	 */
	public function testAnUnreachableJobTableDoesNotBreakTheOpen(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willThrowException(new \RuntimeException('Deadlock found'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$this->collector($this->createMock(EtherpadClient::class), $jobList, $logger)
			->noteBacklog('alice', self::AUTHOR, self::expiredSessions(80));
	}

	/** Nothing about collecting may take a pad server outage further. */
	public function testSurvivesAnUnreachablePadServer(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')
			->willThrowException(new EtherpadClientException('Connection timed out'));

		self::assertSame(
			['deleted' => 0, 'remaining' => 0, 'retry' => true],
			$this->collector($client)->collect('alice', self::AUTHOR),
			'a failed listing is a retry, not an empty backlog',
		);
	}

	private function collector(
		EtherpadClient $client,
		?IJobList $jobList = null,
		?LoggerInterface $logger = null,
		?float $budgetSeconds = null,
	): ExpiredSessionCollector {
		return new ExpiredSessionCollector(
			$client,
			$jobList ?? $this->createMock(IJobList::class),
			$logger ?? $this->createMock(LoggerInterface::class),
			...($budgetSeconds === null ? [] : [$budgetSeconds]),
		);
	}
}
