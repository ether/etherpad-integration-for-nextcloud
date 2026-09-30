<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

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
	 * good, dated now, for the sweep to take: past one chunk, up to the
	 * count the admin confirmed and no further, and nothing else - not a
	 * row whose file is there, nor one seen deleted for good already,
	 * which keeps its date.
	 */
	public function testMarksTheVanishedRowsOnAnAdminsWord(): void {
		$row = static fn (int $fileId, string $state = BindingService::STATE_ACTIVE): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => $state === BindingService::STATE_ACTIVE ? null : 90, 'updated_at' => 100];
		$rows = [$row(1), $row(2, BindingService::STATE_PENDING_DELETE)];
		foreach (range(10, 520) as $fileId) {
			$rows[] = $row($fileId);
		}
		$db = new InMemoryBindingTable($rows, [['fileid' => 1, 'storage' => 1, 'path' => 'files/1.pad']]);
		$clock = new FixedClock(500);
		$logger = $this->createMock(LoggerInterface::class);
		// The sweep's lines that follow read as deletions seen: this one says whose word they were.
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['count'] === 501));
		$service = $this->service($db, $clock, $logger);

		$this->assertSame(501, $service->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), 501));

		$byFile = array_column($db->rows, null, 'file_id');
		$this->assertSame([BindingService::STATE_ACTIVE, null], [$byFile[1]['state'], $byFile[1]['deleted_at']], 'its file is there');
		$this->assertSame([BindingService::STATE_PENDING_DELETE, 90], [$byFile[2]['state'], $byFile[2]['deleted_at']], 'on its way already');
		$this->assertSame([BindingService::STATE_PENDING_DELETE, 500], [$byFile[10]['state'], $byFile[10]['deleted_at']]);
		$this->assertSame([BindingService::STATE_PENDING_DELETE, 500], [$byFile[510]['state'], $byFile[510]['deleted_at']]);
		$this->assertSame(BindingService::STATE_ACTIVE, $byFile[511]['state'], 'past the count confirmed');
		$this->assertSame(10, $service->countVanished());
	}

	/** A budget spent marks nothing more: the admin runs it again for the rest. */
	public function testMarksNothingOnceTheBudgetIsSpent(): void {
		$db = new InMemoryBindingTable([['file_id' => 3, 'pad_id' => 'pad-3', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100]], []);
		$clock = new FixedClock(500);

		$this->assertSame(0, $this->service($db, $clock)->markVanished(new RunBudget($clock, 0.0), 1));
		$this->assertSame(BindingService::STATE_ACTIVE, $db->rows[0]['state']);
	}

	/**
	 * What counts is what the update changed, not what was found: a full
	 * chunk of which nothing could be marked - its rows changed meanwhile
	 * - counts nothing and ends the run, since asked again it would come
	 * back the same.
	 */
	public function testCountsWhatTheUpdateChanged(): void {
		$rows = [];
		foreach (range(1, 500) as $fileId) {
			$rows[] = ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100];
		}
		$db = new InMemoryBindingTable($rows, []);
		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->once())->method('markGone')->willReturn(0);
		$clock = new FixedClock(500);

		$this->assertSame(0, (new ConsistencyCheckService($db, $bindings, $this->createMock(LoggerInterface::class)))->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), 900));
	}

	/**
	 * One vanished file, on an admin's word: its row marked, or - a public
	 * pad's - removed with the pad left in Etherpad, each named in the log.
	 * A protected pad is not forgotten: without a row, its sessions would
	 * stay and a legacy import could claim its group. Neither takes a row
	 * whose file is there, or one on its way already.
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
