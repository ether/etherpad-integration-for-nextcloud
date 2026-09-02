<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredSessionsJob;
use OCA\EtherpadNextcloud\Service\ExpiredSessionCollector;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sweep runs here rather than in the request that noticed the backlog.
 * What this class decides is only whether one run was enough.
 */
class CollectExpiredSessionsJobTest extends TestCase {
	public function testComesBackForWhatDidNotFit(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 250, 'remaining' => 150]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(
			CollectExpiredSessionsJob::class,
			1_000_060,
			['uid' => 'alice', 'authorId' => 'a.author'],
		);

		$this->job($collector, $jobList)->callRun(['uid' => 'alice', 'authorId' => 'a.author']);
	}

	/** A finished sweep must not leave a job behind that finds nothing. */
	public function testStopsWhenThereIsNothingLeft(): void {
		$collector = $this->createMock(ExpiredSessionCollector::class);
		$collector->method('collect')->willReturn(['deleted' => 12, 'remaining' => 0]);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('scheduleAfter');

		$this->job($collector, $jobList)->callRun(['uid' => 'alice', 'authorId' => 'a.author']);
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

		$this->job($collector)->callRun($argument);
	}

	/** @return iterable<string,array{mixed}> */
	public static function unusableArguments(): iterable {
		yield 'null' => [null];
		yield 'a bare uid from an older queue' => ['alice'];
		yield 'no author' => [['uid' => 'alice']];
		yield 'no uid' => [['authorId' => 'a.author']];
		yield 'empty author' => [['uid' => 'alice', 'authorId' => '']];
	}

	private function job(
		ExpiredSessionCollector $collector,
		?IJobList $jobList = null,
	): CollectExpiredSessionsJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_000_000);

		return new CollectExpiredSessionsJob(
			$time,
			$collector,
			$jobList ?? $this->createMock(IJobList::class),
		);
	}
}
