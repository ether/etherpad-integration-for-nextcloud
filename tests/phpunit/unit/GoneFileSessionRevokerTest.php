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
 * The files deleted for good go to jobs in batches, paced over the process,
 * whatever delete_pad_with_file says: this takes no setting. The groups go on
 * to their own jobs, each once, and whose sessions go is GroupSessionRevokerTest's.
 */
class GoneFileSessionRevokerTest extends TestCase {
	private const NOW = 1_000_000;

	/** Only the protected ones, in batches, each a minute after the last. */
	public function testQueuesTheProtectedFilesInBatches(): void {
		$rows = [['file_id' => 5, 'pad_id' => 'public', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE]];
		for ($fileId = 1000; $fileId < 1450; $fileId++) {
			$rows[] = $this->row($fileId);
		}
		[$revoker, $scheduled] = $this->queueing($rows);

		$revoker->queue([5, ...range(1000, 1449)]);

		$this->assertSame([
			[self::NOW, range(1000, 1199)],
			[self::NOW + 60, range(1200, 1399)],
			[self::NOW + 120, range(1400, 1449)],
		], $scheduled->calls);
	}

	/** A trash emptied one file at a time paces as one large delete: by the files, not by the calls. */
	public function testPacesOverAllCallsOfAProcessByTheFiles(): void {
		$rows = [];
		for ($fileId = 1000; $fileId < 1250; $fileId++) {
			$rows[] = $this->row($fileId);
		}
		[$revoker, $scheduled] = $this->queueing($rows);

		for ($fileId = 1000; $fileId < 1201; $fileId++) {
			$revoker->queue([$fileId]);
		}
		$revoker->queue(range(1201, 1249));

		$this->assertSame(self::NOW, $scheduled->calls[0][0]);
		$this->assertSame(self::NOW, $scheduled->calls[199][0], 'the two hundredth');
		$this->assertSame(self::NOW + 60, $scheduled->calls[200][0], 'the next');
		$this->assertSame(self::NOW + 60, $scheduled->calls[201][0]);
	}

	public function testFilesWithoutAProtectedPadQueueNoJob(): void {
		[$revoker, $scheduled] = $this->queueing([['file_id' => 5, 'pad_id' => 'public', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE]]);

		$revoker->queue([5, 6]);
		$revoker->queue([]);

		$this->assertSame([], $scheduled->calls);
	}

	/** A batch of the largest ids stays far below the 32,000 bytes Nextcloud takes as a job's argument. */
	public function testABatchOfTheLargestIdsFitsAJobsArgument(): void {
		$first = PHP_INT_MAX - 300;
		$rows = [];
		for ($offset = 0; $offset < 300; $offset++) {
			$rows[] = $this->row($first + $offset);
		}
		$jobList = $this->createMock(IJobList::class);
		$arguments = [];
		$jobList->method('scheduleAfter')->willReturnCallback(static function (string $job, int $at, mixed $argument) use (&$arguments): void {
			$arguments[] = $argument;
		});
		$clock = new FixedClock(self::NOW);
		$revoker = new GoneFileSessionRevoker(new BindingService(new InMemoryBindingTable($rows), $clock), $this->createMock(GroupSessionRevoker::class), $jobList, $this->createMock(LoggerInterface::class), $clock);

		$revoker->queue(array_map(static fn (array $row): int => $row['file_id'], $rows));

		$this->assertCount(200, $arguments[0]['fileIds']);
		$this->assertLessThan(5000, strlen((string)json_encode($arguments[0])));
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

	/** Each group once, what has no group or no row skipped, nothing left, with a line naming how many. */
	public function testQueuesEachGroupOnce(): void {
		$table = new InMemoryBindingTable([
			$this->row(1, 'one'),
			$this->row(2, 'one'),
			$this->row(3, 'two'),
			['file_id' => 4, 'pad_id' => 'no-group', 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_PENDING_DELETE],
			['file_id' => 5, 'pad_id' => 'g.five$public', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE],
		]);
		$queued = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), ['app' => 'etherpad_nextcloud', 'groups' => 2]);

		$pass = $this->passing($table, $queued, logger: $logger)->pass([1, 2, 3, 4, 5, 6]);

		$this->assertSame(['g.one', 'g.two'], $queued);
		$this->assertSame(['left' => [], 'failed' => false], $pass);
	}

	public function testSaysNothingOfGroupsWhereNoneWasQueued(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$queued = [];

		$this->passing(new InMemoryBindingTable([]), $queued, logger: $logger)->pass([1]);
	}

	/** A group not queued stays in what is left, and what went before does not. */
	public function testKeepsWhatCouldNotBeQueued(): void {
		$table = new InMemoryBindingTable([$this->row(1, 'one'), $this->row(2, 'two'), $this->row(3, 'three')]);
		$queued = [];

		$pass = $this->passing($table, $queued, refuses: 'g.two')->pass([1, 2, 3]);

		$this->assertSame(['g.one'], $queued);
		$this->assertSame(['left' => [2, 3], 'failed' => true], $pass);
	}

	/** Out of time, it queues no further group, and what is left goes to a later pass. */
	public function testStopsWhenTheBudgetIsSpent(): void {
		$table = new InMemoryBindingTable([$this->row(1, 'one'), $this->row(2, 'two'), $this->row(3, 'three')]);
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

		$pass = $this->passing($table, $queued, clock: $clock, onQueue: $onQueue)->pass([1, 2, 3]);

		$this->assertSame(['g.one'], $queued);
		$this->assertSame(['left' => [2, 3], 'failed' => false], $pass);
	}

	/** A look-up that fails leaves all, and says so. */
	public function testALookUpThatFailsLeavesAll(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('protectedPadsOfFiles')->willThrowException(new \RuntimeException('database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not look up the protected pads of files deleted for good.', $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && isset($context['error'])));
		$clock = new FixedClock(self::NOW);

		$pass = (new GoneFileSessionRevoker($bindings, $this->createMock(GroupSessionRevoker::class), $this->createMock(IJobList::class), $logger, $clock))->pass([1, 2]);

		$this->assertSame(['left' => [1, 2], 'failed' => true], $pass);
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array{GoneFileSessionRevoker,object{calls:list<array{int,list<int>}>}}
	 */
	private function queueing(array $rows): array {
		$scheduled = new class {
			/** @var list<array{int,list<int>}> */
			public array $calls = [];
		};
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willReturnCallback(function (string $job, int $at, mixed $argument) use ($scheduled): void {
			$this->assertSame(RevokeGoneFilesSessionsJob::class, $job);
			$scheduled->calls[] = [$at, $argument['fileIds']];
		});
		$clock = new FixedClock(self::NOW);
		return [new GoneFileSessionRevoker(new BindingService(new InMemoryBindingTable($rows), $clock), $this->createMock(GroupSessionRevoker::class), $jobList, $this->createMock(LoggerInterface::class), $clock), $scheduled];
	}

	/**
	 * @param list<string> $queued
	 * @param ?callable(): void $onQueue
	 */
	private function passing(InMemoryBindingTable $table, array &$queued, ?string $refuses = null, ?TestClock $clock = null, ?callable $onQueue = null, ?LoggerInterface $logger = null): GoneFileSessionRevoker {
		$clock ??= new FixedClock(self::NOW);
		$groups = $this->createMock(GroupSessionRevoker::class);
		$groups->method('queue')->willReturnCallback(static function (string $groupId) use (&$queued, $refuses, $onQueue): bool {
			if ($groupId === $refuses) {
				return false;
			}
			$queued[] = $groupId;
			if ($onQueue !== null) {
				$onQueue();
			}
			return true;
		});
		return new GoneFileSessionRevoker(new BindingService($table, $clock), $groups, $this->createMock(IJobList::class), $logger ?? $this->createMock(LoggerInterface::class), $clock);
	}

	/** @return array<string,mixed> */
	private function row(int $fileId, ?string $group = null): array {
		return ['file_id' => $fileId, 'pad_id' => 'g.' . ($group ?? 'g' . $fileId) . '$p-' . $fileId, 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_PENDING_DELETE];
	}
}
