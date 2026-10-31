<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGoneFilesSessionsJob;
use OCA\EtherpadNextcloud\Service\GroupSessionRevoker;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\TestClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Batch arguments, what is queued and log context; retry scheduling is covered by ResumingJobTest. */
class RevokeGoneFilesSessionsJobTest extends TestCase {
	private const NOW = 1_000_000;

	public function testQueuesEveryGroupAndEnds(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');

		$this->assertSame(['g.one', 'g.two'], $this->run_($jobList, ['groupIds' => ['g.one', 'g.two']]));
	}

	/** Out of time, it queues no further group, and a run that got further comes back with what is left. */
	public function testComesBackWithWhatTheBudgetLeft(): void {
		$clock = new class extends TestClock {
			public int $at = 1_000_000;

			public function now(): \DateTimeImmutable {
				return new \DateTimeImmutable('@' . $this->at);
			}
		};
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, 1_001_060, ['groupIds' => ['g.two', 'g.three']]);

		$queued = $this->run_($jobList, ['groupIds' => ['g.one', 'g.two', 'g.three']], clock: $clock, onQueue: static function () use ($clock): void {
			$clock->at += 1000;
		});

		$this->assertSame(['g.one'], $queued);
	}

	/** A group not queued stays in what is left, and what went before does not; a failure backs off even so. */
	public function testKeepsWhatCouldNotBeQueued(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, self::NOW + 60, ['groupIds' => ['g.two', 'g.three'], 'attempt' => 1]);

		$this->assertSame(['g.one'], $this->run_($jobList, ['groupIds' => ['g.one', 'g.two', 'g.three']], refuses: 'g.two'));
	}

	/** A run that got nowhere backs off, or the next would do the same, without end. */
	public function testARunThatGotNowhereBacksOff(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, self::NOW + 300, ['groupIds' => ['g.one'], 'attempt' => 2]);

		$this->assertSame([], $this->run_($jobList, ['groupIds' => ['g.one'], 'attempt' => 1], refuses: 'g.one'));
	}

	/** Giving up names how many groups, and one to look for. */
	public function testGivesUpNamingAGroupToLookFor(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Gave up'), ['app' => 'etherpad_nextcloud', 'groups' => 2, 'groupId' => 'g.one']);

		$this->run_($jobList, ['groupIds' => ['g.one', 'g.two'], 'attempt' => 3], refuses: 'g.one', logger: $logger);
	}

	public function testANextRunThatCannotBeQueuedSaysSo(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willThrowException(new \RuntimeException('database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Could not queue'), $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['groups'] === 1 && $context['groupId'] === 'g.two' && isset($context['error'])));

		$this->run_($jobList, ['groupIds' => ['g.one', 'g.two']], refuses: 'g.two', logger: $logger);
	}

	/** @return iterable<string, array{mixed}> */
	public static function notArguments(): iterable {
		yield 'not an array' => ['10'];
		yield 'no groups' => [['attempt' => 1]];
		yield 'an empty list' => [['groupIds' => []]];
		yield 'no group among them' => [['groupIds' => ['', 5, [5, 6], null]]];
	}

	/** @param mixed $argument */
	#[\PHPUnit\Framework\Attributes\DataProvider('notArguments')]
	public function testAnArgumentNamingNoGroupDoesNothing($argument): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');

		$this->assertSame([], $this->run_($jobList, $argument));
	}

	/** Empty strings and non-string values are discarded; remaining strings keep their order. */
	public function testLeavesOutWhatIsNoGroup(): void {
		$this->assertSame(['g.one', 'g.two'], $this->run_($this->createMock(IJobList::class), ['groupIds' => [5, 'g.one', '', [1], 'g.two']]));
	}

	/**
	 * @param mixed $argument
	 * @param ?callable(): void $onQueue
	 * @return list<string> the groups queued
	 */
	private function run_(IJobList $jobList, $argument, ?string $refuses = null, ?LoggerInterface $logger = null, ?TestClock $clock = null, ?callable $onQueue = null): array {
		$queued = [];
		$groups = $this->createMock(GroupSessionRevoker::class);
		$groups->method('queueUnlessWaiting')->willReturnCallback(static function (string $groupId) use (&$queued, $refuses, $onQueue): bool {
			if ($groupId === $refuses) {
				return false;
			}
			$queued[] = $groupId;
			if ($onQueue !== null) {
				$onQueue();
			}
			return true;
		});
		$job = new RevokeGoneFilesSessionsJob($clock ?? new FixedClock(self::NOW), $groups, $jobList, $logger ?? $this->createMock(LoggerInterface::class));
		$job->setArgument($argument);
		$job->start($jobList);
		return $queued;
	}
}
