<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredSessionsJob;
use OCA\EtherpadNextcloud\Service\ExpiredSessionCollector;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * The sweep runs here rather than in the request that noticed the backlog.
 * What this class decides is only whether one run was enough.
 */
class CollectExpiredSessionsJobTest extends TestCase {
	public function testComesBackForWhatDidNotFit(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 250, 'remaining' => 150, 'retry' => false, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(
			CollectExpiredSessionsJob::class,
			1_000_060,
			['authorId' => 'a.author'],
		);

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author']);
		$job->start($jobList);
	}

	/** A finished sweep must not leave a job behind that finds nothing. */
	public function testStopsWhenThereIsNothingLeft(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 12, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('scheduleAfter');

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author']);
		$job->start($jobList);
	}

	/**
	 * A listing that failed is not an empty backlog. The row is gone before
	 * run() is called, so without re-queueing here the pile waits for
	 * somebody to open a pad and notice it all over again.
	 */
	public function testComesBackAfterAFailedListing(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(
			CollectExpiredSessionsJob::class,
			1_000_060,
			['authorId' => 'a.author', 'attempt' => 1],
		);

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author']);
		$job->start($jobList);
	}

	/**
	 * The backoff has to grow, and the attempt has to travel in the
	 * argument: a plain note from a pad open is then a different row, so an
	 * open cannot reset a retry that is deliberately waiting.
	 */
	public function testBacksOffFurtherOnEachFailure(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(
			CollectExpiredSessionsJob::class,
			1_000_900,
			['authorId' => 'a.author', 'attempt' => 3],
		);

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author', 'attempt' => 2]);
		$job->start($jobList);
	}

	/**
	 * A pad server that has been unreachable for a quarter of an hour is
	 * not going to be helped by a fourth try, and the next open queues a
	 * fresh sweep anyway.
	 */
	public function testGivesUpAfterEnoughFailures(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('scheduleAfter');

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author', 'attempt' => 3]);
		$job->start($jobList);
	}

	/**
	 * A run that deleted something is not an outage.
	 *
	 * The server answered and the pile got smaller, so giving up would drop
	 * thousands of entries because a few were refused. It waits out the
	 * backoff and then carries on as a fresh attempt — the counter resets,
	 * because what the counter is for is deciding when to stop asking a
	 * server that never answers.
	 */
	public function testCarriesOnWhenARefusedRunStillMadeProgress(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 200, 'remaining' => 3000, 'retry' => true, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(
			CollectExpiredSessionsJob::class,
			1_000_300,
			['authorId' => 'a.author'],
		);

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author', 'attempt' => 1]);
		$job->start($jobList);
	}

	/**
	 * Losing the continuation must not be silent. This job's row is gone
	 * before run() is called, so an exception here takes the rest of the
	 * backlog with it and leaves only Nextcloud's generic job error behind.
	 */
	public function testSaysSoWhenItCannotQueueTheNextPass(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 250, 'remaining' => 40, 'retry' => false, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willThrowException(new \RuntimeException('Deadlock found'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$job = $this->job($collector, $jobList, $logger);
		$job->setArgument(['authorId' => 'a.author']);
		$job->start($jobList);
	}

	/**
	 * The backoff table is the limit. A second constant saying how long it
	 * is would be a fact about this array kept somewhere else, and tuning
	 * the delays without noticing would index past its end — inside a cron
	 * worker, with the row already removed and the backlog with it.
	 */
	public function testTheBackoffTableIsItsOwnLimit(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('scheduleAfter');

		$job = $this->job($collector, $jobList);
		$job->setArgument([
			'authorId' => 'a.author',
			'attempt' => count(CollectExpiredSessionsJob::attemptArguments(['authorId' => 'b'])),
		]);
		$job->start($jobList);
	}

	/**
	 * A plain row queued while a run was in progress stands down when a
	 * retry is waiting.
	 *
	 * QueuedJob removes its own row before running, so for the length of a
	 * run nothing says a sweep exists: an open queues a plain row, the
	 * failing run then adds its retry behind it, and the plain one would
	 * run at once — undoing exactly the waiting the backoff is for, during
	 * the outage it exists for.
	 */
	public function testStandsDownWhileABackedOffRetryIsWaiting(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->expects(self::never())->method('collect');

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturnCallback(
			static fn (string $job, mixed $argument): bool => is_array($argument)
				&& ($argument['attempt'] ?? 0) === 2
		);

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author']);
		$job->start($jobList);
	}

	/** A retry itself must not stand down — it is the one that was waiting. */
	public function testARetryRunsEvenThoughItIsItselfQueued(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->expects(self::once())->method('collect')
			->willReturn(['deleted' => 5, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(true);

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author', 'attempt' => 2]);
		$job->start($jobList);
	}

	/**
	 * A finished sweep comes back when the earliest session still standing
	 * becomes collectable — that row is also what tells an open a sweep is
	 * already accounted for.
	 */
	public function testComesBackWhenTheNextSessionFallsDue(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')
			->willReturn(['deleted' => 3, 'remaining' => 0, 'retry' => false, 'nextDueAt' => 1_000_900]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(
			CollectExpiredSessionsJob::class,
			1_000_900,
			['authorId' => 'a.author'],
		);

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author']);
		$job->start($jobList);
	}

	/** Every retry shape the collector has to recognise before queueing one. */
	public function testNamesEveryRetryArgumentItCanProduce(): void {
		self::assertSame([
			['authorId' => 'a.author', 'attempt' => 1],
			['authorId' => 'a.author', 'attempt' => 2],
			['authorId' => 'a.author', 'attempt' => 3],
		], CollectExpiredSessionsJob::attemptArguments(['authorId' => 'a.author']));
	}


	/** A run that did its work continues, it does not retry. */
	public function testAContinuationIsNotARetry(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 250, 'remaining' => 40, 'retry' => false, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(
			CollectExpiredSessionsJob::class,
			1_000_060,
			['authorId' => 'a.author'],
		);

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author', 'attempt' => 2]);
		$job->start($jobList);
	}

	/** A row is data: an attempt count out of range must not index the backoff table. */
	public function testTreatsANonsenseAttemptAsTheFirst(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(
			CollectExpiredSessionsJob::class,
			1_000_060,
			['authorId' => 'a.author', 'attempt' => 1],
		);

		$job = $this->job($collector, $jobList);
		$job->setArgument(['authorId' => 'a.author', 'attempt' => -5]);
		$job->start($jobList);
	}

	/**
	 * Job arguments outlive the code that wrote them: a row queued by an
	 * older version, or one whose author was cleared since, must not reach
	 * Etherpad with an empty id and list somebody else's sessions.
	 *
	 * @param mixed $argument
	 */
	#[DataProvider('unusableArguments')]
	public function testIgnoresAnArgumentItCannotUse($argument): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->expects(self::never())->method('collect');

		$jobList = $this->createMock(IJobList::class);
		$job = $this->job($collector, $jobList);
		$job->setArgument($argument);
		$job->start($jobList);
	}

	/** @return iterable<string,array{mixed}> */
	public static function unusableArguments(): iterable {
		yield 'null' => [null];
		yield 'a bare string from an older queue' => ['alice'];
		yield 'no author' => [[]];
		yield 'empty author' => [['authorId' => '']];
		// The shape this job used to be queued with, when the argument
		// still carried a uid — and for a public link that uid was the
		// share token.
		yield 'an older row without an author' => [['uid' => 'alice']];
	}

	private function job(
		ExpiredSessionCollector $collector,
		?IJobList $jobList = null,
		?LoggerInterface $logger = null,
	): CollectExpiredSessionsJob {
		$time = new FixedClock(1_000_000);

		return new CollectExpiredSessionsJob(
			$time,
			$collector,
			$jobList ?? $this->createMock(IJobList::class),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
