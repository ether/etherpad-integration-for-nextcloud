<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGoneFilesSessionsJob;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\GoneFileSessionRevoker;
use OCA\EtherpadNextcloud\Service\GroupSessionRevoker;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use OCA\EtherpadNextcloud\Tests\Support\TestClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The groups of files deleted for good go to jobs in batches, paced over the
 * process, whatever delete_pad_with_file says: this takes no setting. They go
 * on to their own jobs, and whose sessions go is GroupSessionRevokerTest's.
 */
class GoneFileSessionRevokerTest extends TestCase {
	private const NOW = 1_000_000;

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

	/** A trash emptied one file at a time paces as one large delete, and a group already handed over stays out. */
	public function testPacesOverAllCallsOfAProcess(): void {
		$rows = [];
		for ($fileId = 1000; $fileId < 1110; $fileId++) {
			$rows[] = $this->row($fileId);
		}
		$rows[] = $this->row(2000, 'g.g1000');
		[$revoker, $scheduled] = $this->queueing($rows);

		for ($fileId = 1000; $fileId < 1101; $fileId++) {
			$revoker->queue([$fileId]);
		}
		$before = count($scheduled->calls);
		$revoker->queue([2000]);
		$this->assertCount($before, $scheduled->calls, 'its group went with the first');
		$revoker->queue([1101]);

		$this->assertSame(self::NOW, $scheduled->calls[0][0]);
		$this->assertSame(self::NOW, $scheduled->calls[99][0], 'the hundredth');
		$this->assertSame(self::NOW + 30, $scheduled->calls[100][0], 'the next');
		$this->assertSame(self::NOW + 30, $scheduled->calls[101][0]);
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
		$revoker = new GoneFileSessionRevoker(new BindingService(new InMemoryBindingTable($rows), $clock), $this->createMock(GroupSessionRevoker::class), $jobList, $this->createMock(LoggerInterface::class), $clock);

		$revoker->queue(range(1, 150));

		$this->assertCount(100, $arguments[0]['groupIds']);
		$this->assertLessThan(32000, strlen((string)json_encode($arguments[0])));
	}

	/** Never fails what called it: the delete goes on, with a line. */
	public function testAQueueingThatFailsSaysSo(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willThrowException(new \RuntimeException('database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && isset($context['error'])));
		$clock = new FixedClock(self::NOW);

		(new GoneFileSessionRevoker(new BindingService(new InMemoryBindingTable([$this->row(1)]), $clock), $this->createMock(GroupSessionRevoker::class), $jobList, $logger, $clock))->queue([1]);
	}

	public function testQueuesEveryGroup(): void {
		$queued = [];

		$pass = $this->passing($queued)->pass(['g.one', 'g.two']);

		$this->assertSame(['g.one', 'g.two'], $queued);
		$this->assertSame(['left' => [], 'failed' => false], $pass);
	}

	/** A group not queued stays in what is left, and what went before does not. */
	public function testKeepsWhatCouldNotBeQueued(): void {
		$queued = [];

		$pass = $this->passing($queued, refuses: 'g.two')->pass(['g.one', 'g.two', 'g.three']);

		$this->assertSame(['g.one'], $queued);
		$this->assertSame(['left' => ['g.two', 'g.three'], 'failed' => true], $pass);
	}

	/** Out of time, it queues no further group, and what is left goes to a later pass. */
	public function testStopsWhenTheBudgetIsSpent(): void {
		$queued = [];
		$clock = new class extends TestClock {
			public int $at = 1000;

			public function now(): \DateTimeImmutable {
				return new \DateTimeImmutable('@' . $this->at);
			}
		};
		$onQueue = static function () use ($clock): void {
			$clock->at += 1000;
		};

		$pass = $this->passing($queued, clock: $clock, onQueue: $onQueue)->pass(['g.one', 'g.two', 'g.three']);

		$this->assertSame(['g.one'], $queued);
		$this->assertSame(['left' => ['g.two', 'g.three'], 'failed' => false], $pass);
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array{GoneFileSessionRevoker,object{calls:list<array{int,list<string>}>}}
	 */
	private function queueing(array $rows): array {
		$scheduled = new class {
			/** @var list<array{int,list<string>}> */
			public array $calls = [];
		};
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willReturnCallback(function (string $job, int $at, mixed $argument) use ($scheduled): void {
			$this->assertSame(RevokeGoneFilesSessionsJob::class, $job);
			$scheduled->calls[] = [$at, $argument['groupIds']];
		});
		$clock = new FixedClock(self::NOW);
		return [new GoneFileSessionRevoker(new BindingService(new InMemoryBindingTable($rows), $clock), $this->createMock(GroupSessionRevoker::class), $jobList, $this->createMock(LoggerInterface::class), $clock), $scheduled];
	}

	/**
	 * @param list<string> $queued
	 * @param ?callable(): void $onQueue
	 */
	private function passing(array &$queued, ?string $refuses = null, ?TestClock $clock = null, ?callable $onQueue = null): GoneFileSessionRevoker {
		$clock ??= new FixedClock(self::NOW);
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
		return new GoneFileSessionRevoker($this->createMock(BindingService::class), $groups, $this->createMock(IJobList::class), $this->createMock(LoggerInterface::class), $clock);
	}

	/** @return array<string,mixed> */
	private function row(int $fileId, ?string $group = null): array {
		return ['file_id' => $fileId, 'pad_id' => ($group ?? 'g.g' . $fileId) . '$p-' . $fileId, 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_PENDING_DELETE];
	}
}
