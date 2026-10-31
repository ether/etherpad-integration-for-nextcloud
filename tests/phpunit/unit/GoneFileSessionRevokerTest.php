<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGoneFilesSessionsJob;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\GoneFileSessionRevoker;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use OCA\EtherpadNextcloud\Tests\Support\TestClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The groups of files deleted for good go to jobs in batches, paced over the
 * process, whatever delete_pad_with_file says: this takes no setting. They go
 * on to their own jobs (RevokeGoneFilesSessionsJobTest), and whose sessions go
 * is GroupSessionRevokerTest's.
 */
class GoneFileSessionRevokerTest extends TestCase {
	public const NOW = 1_000_000;

	/** Only protected pads' groups, each once, in batches, each thirty seconds after the last. */
	public function testQueuesTheGroupsInBatches(): void {
		$rows = [
			['file_id' => 5, 'pad_id' => 'g.public$p', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE],
			['file_id' => 6, 'pad_id' => 'no-group', 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_PENDING_DELETE],
			$this->row(7, 'g.g1000'),
		];
		for ($fileId = 1000; $fileId < 1225; $fileId++) {
			$rows[] = $this->row($fileId);
		}
		[$revoker, $scheduled] = $this->queueing($rows);

		$revoker->queue([5, 6, 7, ...range(1000, 1224)]);

		$this->assertSame([self::NOW, self::NOW + 30, self::NOW + 60], array_column($scheduled->calls, 0));
		$this->assertSame(['g.g1000', 'g.g1001'], array_slice($scheduled->calls[0][1], 0, 2));
		$this->assertSame([100, 100, 25], array_map('count', array_column($scheduled->calls, 1)));
	}

	/** A trash emptied one file at a time paces as one large delete. */
	public function testPacesOverAllCallsOfAProcess(): void {
		$rows = [];
		for ($fileId = 1000; $fileId < 1201; $fileId++) {
			$rows[] = $this->row($fileId);
		}
		[$revoker, $scheduled] = $this->queueing($rows);

		for ($fileId = 1000; $fileId < 1201; $fileId++) {
			$revoker->queue([$fileId]);
		}

		$this->assertSame(self::NOW, $scheduled->calls[0][0]);
		$this->assertSame(self::NOW + 29, $scheduled->calls[99][0], 'the hundredth');
		$this->assertSame(self::NOW + 30, $scheduled->calls[100][0], 'a hundred groups on');
		$this->assertSame(self::NOW + 60, $scheduled->calls[200][0], 'two hundred groups on');
	}

	/**
	 * A process that lives long - a worker in a loop, a long cron run - owes
	 * nothing for what it handed over long ago.
	 */
	public function testThePaceRunsOutAsTimePasses(): void {
		$rows = [];
		for ($fileId = 1000; $fileId < 1200; $fileId++) {
			$rows[] = $this->row($fileId);
		}
		$rows[] = $this->row(2000);
		$clock = new class extends TestClock {
			public int $at = GoneFileSessionRevokerTest::NOW;

			public function now(): \DateTimeImmutable {
				return new \DateTimeImmutable('@' . $this->at);
			}
		};
		[$revoker, $scheduled] = $this->queueing($rows, $clock);

		$revoker->queue(range(1000, 1199));
		$clock->at += 3600;
		$revoker->queue([2000]);

		$this->assertSame(self::NOW + 3600, $scheduled->calls[2][0]);
	}

	/**
	 * Its other pad's file may have kept the group's sessions the first time,
	 * so a later file of the same group, in the same process, hands it over
	 * again.
	 */
	public function testAGroupHandedOverBeforeIsHandedOverAgain(): void {
		[$revoker, $scheduled] = $this->queueing([$this->row(1, 'g.one'), $this->row(2, 'g.one')]);

		$revoker->queue([1]);
		$revoker->queue([2]);

		$this->assertSame([['g.one'], ['g.one']], array_column($scheduled->calls, 1));
	}

	public function testFilesWithoutAGroupQueueNoJob(): void {
		[$revoker, $scheduled] = $this->queueing([['file_id' => 5, 'pad_id' => 'public', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE]]);

		$revoker->queue([5, 6]);
		$revoker->queue([]);

		$this->assertSame([], $scheduled->calls);
	}

	/** Long alphanumeric group names exercise the batch limit without JSON escaping. */
	public function testABatchOfTheLongestIdsFitsAJobsArgument(): void {
		$rows = [];
		for ($fileId = 1; $fileId <= 150; $fileId++) {
			$rows[] = ['file_id' => $fileId, 'pad_id' => substr('g.' . $fileId . str_repeat('x', 255), 0, 253) . '$p', 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_PENDING_DELETE];
		}
		$jobList = $this->createMock(IJobList::class);
		$arguments = [];
		$jobList->method('scheduleAfter')->willReturnCallback(static function (string $job, int $at, mixed $argument) use (&$arguments): void {
			$arguments[] = $argument;
		});
		$clock = new FixedClock(self::NOW);
		$revoker = new GoneFileSessionRevoker(new BindingService(new InMemoryBindingTable($rows), $clock), $jobList, $this->createMock(LoggerInterface::class), $clock);

		$revoker->queue(range(1, 150));

		$this->assertCount(100, $arguments[0]['groupIds']);
		$this->assertLessThan(32000, strlen((string)json_encode($arguments[0])));
	}

	/** Never fails what called it: the delete goes on, with a line saying how much went to no job. */
	public function testABatchThatCannotBeQueuedSaysSoAndLeavesTheNextToTry(): void {
		$rows = [];
		for ($fileId = 1000; $fileId < 1150; $fileId++) {
			$rows[] = $this->row($fileId);
		}
		$jobList = $this->createMock(IJobList::class);
		$tried = [];
		$jobList->method('scheduleAfter')->willReturnCallback(static function (string $job, int $at, mixed $argument) use (&$tried): void {
			$tried[] = $at;
			if (count($tried) === 1) {
				throw new \RuntimeException('database went away');
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Could not queue'), $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['groups'] === 100 && $context['groupId'] === 'g.g1000' && isset($context['error'])));
		$clock = new FixedClock(self::NOW);

		(new GoneFileSessionRevoker(new BindingService(new InMemoryBindingTable($rows), $clock), $jobList, $logger, $clock))->queue(range(1000, 1149));

		$this->assertSame([self::NOW, self::NOW], $tried, 'a batch not queued adds nothing to the pace');
	}

	public function testGroupsThatCannotBeReadSaySo(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('protectedPadsOfFiles')->willThrowException(new \RuntimeException('database went away'));
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Could not read'), $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['files'] === 2 && isset($context['error'])));

		(new GoneFileSessionRevoker($bindings, $jobList, $logger, new FixedClock(self::NOW)))->queue([1, 2]);
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array{GoneFileSessionRevoker,object{calls:list<array{int,list<string>}>}}
	 */
	private function queueing(array $rows, ?TestClock $clock = null): array {
		$scheduled = new class {
			/** @var list<array{int,list<string>}> */
			public array $calls = [];
		};
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willReturnCallback(function (string $job, int $at, mixed $argument) use ($scheduled): void {
			$this->assertSame(RevokeGoneFilesSessionsJob::class, $job);
			$scheduled->calls[] = [$at, $argument['groupIds']];
		});
		$clock ??= new FixedClock(self::NOW);
		return [new GoneFileSessionRevoker(new BindingService(new InMemoryBindingTable($rows), $clock), $jobList, $this->createMock(LoggerInterface::class), $clock), $scheduled];
	}

	/** @return array<string,mixed> */
	private function row(int $fileId, ?string $group = null): array {
		return ['file_id' => $fileId, 'pad_id' => ($group ?? 'g.g' . $fileId) . '$p-' . $fileId, 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_PENDING_DELETE];
	}
}
