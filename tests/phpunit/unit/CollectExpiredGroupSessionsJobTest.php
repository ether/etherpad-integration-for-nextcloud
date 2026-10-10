<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredGroupSessionsJob;
use OCA\EtherpadNextcloud\Service\ExpiredSessionCollector;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The passes are SessionSweepJob's, which CollectExpiredSessionsJobTest
 * holds to its rules; this holds the group's sweep to its argument, its
 * pass and what it says of the rest.
 */
class CollectExpiredGroupSessionsJobTest extends TestCase {
	private const GROUP = 'g.AAAAAAAAAAAAAAAA';

	/** A pass is the collector's over the group, and comes back as the earliest session falls due. */
	public function testCollectsTheGroupAndComesBackWhenTheNextFallsDue(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->expects(self::once())->method('collectGroup')->with(self::GROUP)
			->willReturn(['deleted' => 3, 'remaining' => 0, 'retry' => false, 'nextDueAt' => 1_003_600]);
		$collector->expects(self::never())->method('collect');
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(CollectExpiredGroupSessionsJob::class, 1_003_600, ['groupId' => self::GROUP]);

		$this->start($collector, $jobList, ['groupId' => self::GROUP]);
	}

	/** Giving up is info, as the next open of the link queues the sweep anew. */
	public function testSaysItGaveUpAsInfo(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collectGroup')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::once())->method('info')->with(
			'Gave up a sweep of a group\'s expired Etherpad sessions after three retries without progress; the rest waits for another open.',
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP),
		);

		$this->start($collector, $this->createMock(IJobList::class), ['groupId' => self::GROUP, 'attempt' => 3], $logger);
	}

	/** An author's row is the other collector's. */
	public function testIgnoresAnArgumentWithoutAGroup(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->expects(self::never())->method('collectGroup');

		$this->start($collector, $this->createMock(IJobList::class), ['authorId' => 'a.author']);
	}

	/** @param array<string,string|int> $argument */
	private function start(ExpiredSessionCollector $collector, IJobList $jobList, array $argument, ?LoggerInterface $logger = null): void {
		$job = new CollectExpiredGroupSessionsJob(new FixedClock(1_000_000), $collector, $jobList, $logger ?? $this->createMock(LoggerInterface::class));
		$job->setArgument($argument);
		$job->start($jobList);
	}
}
