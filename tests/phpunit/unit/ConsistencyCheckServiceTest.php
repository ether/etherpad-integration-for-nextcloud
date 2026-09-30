<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the consistency check counts: the vanished rows - their file gone
 * from the file cache, still active, never seen deleted for good - which
 * the app leaves to an admin. A row seen deleted for good is on its way.
 */
class ConsistencyCheckServiceTest extends TestCase {
	public function testCountsTheVanishedRows(): void {
		$row = static fn (int $fileId, string $state = BindingService::STATE_ACTIVE): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => $state === BindingService::STATE_ACTIVE ? null : 90, 'updated_at' => 100];
		$db = new InMemoryBindingTable([
			$row(1),
			$row(2, BindingService::STATE_PENDING_DELETE),
			$row(3),
			$row(4, BindingService::STATE_PENDING_DELETE),
			$row(5),
			$row(6),
		], [['fileid' => 1, 'storage' => 1, 'path' => 'files/1.pad']]);

		$result = $this->service($db)->run(2);

		$this->assertSame(3, $result['vanished_file_count'], 'not the ones seen deleted for good, nor the one still there');
		$this->assertSame(['vanished_file_count', 'samples'], array_keys($result));
		$this->assertSame(['vanished_files'], array_keys($result['samples']));
		$this->assertSame([['file_id' => 3, 'pad_id' => 'pad-3', 'access_mode' => BindingService::ACCESS_PUBLIC], ['file_id' => 5, 'pad_id' => 'pad-5', 'access_mode' => BindingService::ACCESS_PUBLIC]], $result['samples']['vanished_files']);
	}

	/**
	 * On an admin's word the vanished rows are marked as files deleted for
	 * good, dated now, for the sweep to take: past one chunk, and nothing
	 * else - not a row whose file is there, nor one seen deleted for good
	 * already, which keeps its date. The list is asked once, however many
	 * chunks it makes: the query reads the whole file cache each time.
	 */
	public function testMarksTheVanishedRowsOnAnAdminsWord(): void {
		$row = static fn (int $fileId, string $state = BindingService::STATE_ACTIVE): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => $state === BindingService::STATE_ACTIVE ? null : 90, 'updated_at' => 100];
		$rows = [$row(1), $row(2, BindingService::STATE_PENDING_DELETE)];
		foreach (range(10, 510) as $fileId) {
			$rows[] = $row($fileId);
		}
		$db = new InMemoryBindingTable($rows, [['fileid' => 1, 'storage' => 1, 'path' => 'files/1.pad']]);
		$clock = new FixedClock(500);
		$logger = $this->createMock(LoggerInterface::class);
		// The sweep's lines that follow read as deletions seen: this one says whose word they were.
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['count'] === 501));
		$service = $this->service($db, $clock, $logger);

		$this->assertSame(501, $service->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), 501));
		$this->assertSame([501], $db->read, 'one query for the ids of both chunks');

		$byFile = array_column($db->rows, null, 'file_id');
		$this->assertSame([BindingService::STATE_ACTIVE, null], [$byFile[1]['state'], $byFile[1]['deleted_at']], 'its file is there');
		$this->assertSame([BindingService::STATE_PENDING_DELETE, 90], [$byFile[2]['state'], $byFile[2]['deleted_at']], 'on its way already');
		$this->assertSame([BindingService::STATE_PENDING_DELETE, 500], [$byFile[10]['state'], $byFile[10]['deleted_at']]);
		$this->assertSame([BindingService::STATE_PENDING_DELETE, 500], [$byFile[510]['state'], $byFile[510]['deleted_at']]);
		$this->assertSame(0, $service->countVanished());
	}

	/**
	 * Only a list of exactly as many as the admin confirmed is taken, and
	 * the rows to mark and their number come from one query. Shown file 10
	 * and confirmed one: file 1 vanished since, and a limit alone would mark
	 * file 1, which the admin never saw. Nothing is marked, fewer than
	 * confirmed neither.
	 */
	public function testALongerOrShorterListThanConfirmedIsNotTaken(): void {
		$row = static fn (int $fileId): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100];
		$db = new InMemoryBindingTable([$row(1), $row(10)], []);
		$clock = new FixedClock(500);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$service = $this->service($db, $clock, $logger);

		$this->assertNull($service->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), 1), 'grown since');
		$this->assertNull($service->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), 3), 'shrunk since');
		$this->assertSame([BindingService::STATE_ACTIVE, BindingService::STATE_ACTIVE], array_column($db->rows, 'state'));
	}

	/**
	 * A list longer than one call collects is counted on its own, and the
	 * call marks what it collects: the rest needs another.
	 */
	public function testAListPastOneCallsReachIsMarkedInPart(): void {
		$rows = [];
		foreach (range(1, 5) as $fileId) {
			$rows[] = ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100];
		}
		$clock = new FixedClock(500);
		$logger = $this->createMock(LoggerInterface::class);
		$service = static fn (InMemoryBindingTable $db): ConsistencyCheckService => new class($db, new BindingService($db, $clock), $logger) extends ConsistencyCheckService {
			protected function markMax(): int {
				return 3;
			}
		};

		$this->assertNull($service(new InMemoryBindingTable($rows, []))->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), 6), 'not the count confirmed');
		$db = new InMemoryBindingTable($rows, []);
		$this->assertSame(3, $service($db)->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), 5));
		$this->assertSame([1, 2, 3], array_column(array_filter($db->rows, static fn (array $r): bool => $r['state'] === BindingService::STATE_PENDING_DELETE), 'file_id'));
	}

	/** A budget spent marks nothing more: the admin runs it again for the rest. */
	public function testMarksNothingOnceTheBudgetIsSpent(): void {
		$db = new InMemoryBindingTable([['file_id' => 3, 'pad_id' => 'pad-3', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100]], []);
		$clock = new FixedClock(500);

		$this->assertSame(0, $this->service($db, $clock)->markVanished(new RunBudget($clock, 0.0), 1));
		$this->assertSame(BindingService::STATE_ACTIVE, $db->rows[0]['state']);
	}

	/**
	 * What counts is what the updates changed, not what was found: a row
	 * that changed meanwhile - forgotten, say - is not reported as marked.
	 * And what was marked is logged whatever ends the run: a chunk that
	 * fails leaves the marks before it standing, to be deleted.
	 */
	public function testCountsAndLogsWhatTheUpdatesChanged(): void {
		$rows = [];
		foreach (range(1, 1100) as $fileId) {
			$rows[] = ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100];
		}
		$clock = new FixedClock(500);

		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->exactly(3))->method('markGone')->willReturnOnConsecutiveCalls(499, 0, 100);
		$this->assertSame(599, (new ConsistencyCheckService(new InMemoryBindingTable($rows, []), $bindings, $this->createMock(LoggerInterface::class)))->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), 1100));

		$failing = $this->createMock(BindingService::class);
		$failing->method('markGone')->willReturnOnConsecutiveCalls(500, $this->throwException(new \RuntimeException('database went away')));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['count'] === 500));
		try {
			(new ConsistencyCheckService(new InMemoryBindingTable($rows, []), $failing, $logger))->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), 1100);
			$this->fail('The failure should reach the caller.');
		} catch (\RuntimeException $e) {
			$this->assertSame('database went away', $e->getMessage());
		}
	}

	/**
	 * Once the row is gone, nothing is left that can fail before the log
	 * has the pad's id: the file cache is asked before, and not again. A
	 * question after the delete that failed would leave the row removed
	 * and the pad named nowhere.
	 */
	public function testNothingIsAskedBetweenARowRemovedAndItsLogLine(): void {
		$removed = false;
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('findByFileId')->with(7)->willReturn(new Binding(fileId: 7, padId: 'pad-7', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
		$bindings->method('isFileGone')->willReturnCallback(static function () use (&$removed): bool {
			if ($removed) {
				throw new \RuntimeException('database went away');
			}
			return true;
		});
		$bindings->method('deleteInState')->willReturnCallback(static function () use (&$removed): bool {
			$removed = true;
			return true;
		});
		$bindings->expects($this->never())->method('createBinding');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['padId'] === 'pad-7' && $context['fileId'] === 7));

		$this->assertSame(ConsistencyCheckService::FORGOTTEN, (new ConsistencyCheckService(new InMemoryBindingTable([], []), $bindings, $logger))->forgetVanished(7));
	}

	/** A file that is there when asked keeps its row: nothing is removed. */
	public function testAFileThatIsThereIsNotForgotten(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('findByFileId')->with(7)->willReturn(new Binding(fileId: 7, padId: 'pad-7', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
		$bindings->method('isFileGone')->with(7)->willReturn(false);
		$bindings->expects($this->never())->method('deleteInState');

		$this->assertSame(ConsistencyCheckService::NOT_VANISHED, (new ConsistencyCheckService(new InMemoryBindingTable([], []), $bindings, $this->createMock(LoggerInterface::class)))->forgetVanished(7));
	}

	/**
	 * One vanished file, on an admin's word: its row marked, or - a public
	 * pad's - removed with the pad left in Etherpad, each named in the log.
	 * A protected pad is not forgotten. Neither takes a row whose file is
	 * there, or one on its way already.
	 */
	public function testTakesOneVanishedFileAtATime(): void {
		$row = static fn (int $fileId, string $mode, string $state = BindingService::STATE_ACTIVE): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => $mode, 'state' => $state, 'deleted_at' => $state === BindingService::STATE_ACTIVE ? null : 90, 'updated_at' => 100];
		$db = new InMemoryBindingTable([
			$row(1, BindingService::ACCESS_PUBLIC),
			$row(2, BindingService::ACCESS_PUBLIC, BindingService::STATE_PENDING_DELETE),
			$row(3, BindingService::ACCESS_PROTECTED),
			$row(4, BindingService::ACCESS_PUBLIC),
			$row(5, BindingService::ACCESS_PROTECTED),
			$row(6, BindingService::ACCESS_PROTECTED, BindingService::STATE_PENDING_DELETE),
		], [['fileid' => 1, 'storage' => 1, 'path' => 'files/1.pad']]);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged[] = [$context['fileId'], $context['padId'] ?? null];
		});
		$service = $this->service($db, new FixedClock(500), $logger);

		$this->assertTrue($service->markVanishedFile(3));
		$this->assertFalse($service->markVanishedFile(1), 'its file is there');
		$this->assertFalse($service->markVanishedFile(2), 'on its way already');
		$this->assertSame(ConsistencyCheckService::FORGOTTEN, $service->forgetVanished(4));
		$this->assertSame(ConsistencyCheckService::PROTECTED_PAD, $service->forgetVanished(5));
		$this->assertSame(ConsistencyCheckService::NOT_VANISHED, $service->forgetVanished(1), 'its file is there');
		$this->assertSame(ConsistencyCheckService::NOT_VANISHED, $service->forgetVanished(2), 'on its way already');
		$this->assertSame(ConsistencyCheckService::NOT_VANISHED, $service->forgetVanished(4), 'gone already');
		$this->assertSame(ConsistencyCheckService::NOT_VANISHED, $service->forgetVanished(6), 'a protected pad on its way is no vanished one: said as that, not as protected');

		$states = array_map(static fn (array $r): array => [$r['file_id'], $r['state'], $r['deleted_at']], $db->rows);
		$this->assertSame([[1, BindingService::STATE_ACTIVE, null], [2, BindingService::STATE_PENDING_DELETE, 90], [3, BindingService::STATE_PENDING_DELETE, 500], [5, BindingService::STATE_ACTIVE, null], [6, BindingService::STATE_PENDING_DELETE, 90]], $states);
		$this->assertSame([[3, null], [4, 'pad-4']], $logged);
	}

	private function service(InMemoryBindingTable $db, ?FixedClock $clock = null, ?LoggerInterface $logger = null): ConsistencyCheckService {
		return new ConsistencyCheckService($db, new BindingService($db, $clock ?? new FixedClock()), $logger ?? $this->createMock(LoggerInterface::class));
	}
}
