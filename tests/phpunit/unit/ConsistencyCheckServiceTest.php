<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use PHPUnit\Framework\TestCase;

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
	 * good, dated now, for the sweep to take: all of them, past one chunk,
	 * and nothing else - not a row whose file is there, nor one seen
	 * deleted for good already, which keeps its date.
	 */
	public function testMarksTheVanishedRowsOnAnAdminsWord(): void {
		$row = static fn (int $fileId, string $state = BindingService::STATE_ACTIVE): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => $state === BindingService::STATE_ACTIVE ? null : 90, 'updated_at' => 100];
		$rows = [$row(1), $row(2, BindingService::STATE_PENDING_DELETE)];
		foreach (range(10, 510) as $fileId) {
			$rows[] = $row($fileId);
		}
		$db = new InMemoryBindingTable($rows, [['fileid' => 1, 'storage' => 1, 'path' => 'files/1.pad']]);
		$clock = new FixedClock(500);
		$service = $this->service($db, $clock);

		$this->assertSame(501, $service->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS)));

		$byFile = array_column($db->rows, null, 'file_id');
		$this->assertSame([BindingService::STATE_ACTIVE, null], [$byFile[1]['state'], $byFile[1]['deleted_at']], 'its file is there');
		$this->assertSame([BindingService::STATE_PENDING_DELETE, 90], [$byFile[2]['state'], $byFile[2]['deleted_at']], 'on its way already');
		$this->assertSame([BindingService::STATE_PENDING_DELETE, 500], [$byFile[10]['state'], $byFile[10]['deleted_at']]);
		$this->assertSame([BindingService::STATE_PENDING_DELETE, 500], [$byFile[510]['state'], $byFile[510]['deleted_at']]);
		$this->assertSame(0, $service->countVanished());
	}

	/** A budget spent marks nothing more: the admin runs it again for the rest. */
	public function testMarksNothingOnceTheBudgetIsSpent(): void {
		$db = new InMemoryBindingTable([['file_id' => 3, 'pad_id' => 'pad-3', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100]], []);
		$clock = new FixedClock(500);

		$this->assertSame(0, $this->service($db, $clock)->markVanished(new RunBudget($clock, 0.0)));
		$this->assertSame(BindingService::STATE_ACTIVE, $db->rows[0]['state']);
	}

	/**
	 * A full chunk of which nothing could be marked - its rows changed
	 * meanwhile - ends the run: asked again, it would come back the same.
	 */
	public function testStopsAtAChunkThatMarksNothing(): void {
		$rows = [];
		foreach (range(1, 500) as $fileId) {
			$rows[] = ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100];
		}
		$db = new InMemoryBindingTable($rows, []);
		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->once())->method('markIfGone')->willReturn([]);
		$clock = new FixedClock(500);

		$this->assertSame(0, (new ConsistencyCheckService($db, $bindings))->markVanished(new RunBudget($clock, RunBudget::DEFAULT_SECONDS)));
	}

	private function service(InMemoryBindingTable $db, ?FixedClock $clock = null): ConsistencyCheckService {
		return new ConsistencyCheckService($db, new BindingService($db, $clock ?? new FixedClock()));
	}
}
