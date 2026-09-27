<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingNotCreatedException;
use OCA\EtherpadNextcloud\Exception\BindingMismatchException;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use PHPUnit\Framework\TestCase;

class BindingServiceTest extends TestCase {
	/**
	 * The file's own row is the one compared, and it has to name the pad,
	 * in the access mode, and be active. Another file's row is no binding
	 * for this one, and a mode the app does not know is refused first.
	 */
	public function testAssertConsistentMappingHoldsTheFilesRowToPadModeAndState(): void {
		$cases = [
			'consistent' => [self::bindingRow(10, 'pad-a', BindingService::STATE_ACTIVE), 'pad-a', BindingService::ACCESS_PUBLIC, null, null],
			'another pad' => [self::bindingRow(10, 'pad-a', BindingService::STATE_ACTIVE), 'pad-b', BindingService::ACCESS_PUBLIC, BindingMismatchException::class, 'Binding pad ID mismatch.'],
			'another mode' => [self::bindingRow(10, 'pad-a', BindingService::STATE_ACTIVE, BindingService::ACCESS_PROTECTED), 'pad-a', BindingService::ACCESS_PUBLIC, BindingMismatchException::class, 'Binding access mode mismatch.'],
			// Seen deleted for good, yet opened: the deletion did not happen.
			'a file seen deleted' => [self::bindingRow(10, 'pad-a', BindingService::STATE_PENDING_DELETE), 'pad-a', BindingService::ACCESS_PUBLIC, null, null],
			'a state the app does not know' => [self::bindingRow(10, 'pad-a', 'trashed'), 'pad-a', BindingService::ACCESS_PUBLIC, BindingException::class, 'Pad binding is not active.'],
			'another file\'s row' => [self::bindingRow(11, 'pad-a', BindingService::STATE_ACTIVE), 'pad-a', BindingService::ACCESS_PUBLIC, MissingBindingException::class, 'No binding exists for this file.'],
			'unknown mode' => [self::bindingRow(10, 'pad-a', BindingService::STATE_ACTIVE), 'pad-a', 'legacy', BindingException::class, 'Unsupported access mode: legacy'],
		];
		foreach ($cases as $case => [$row, $padId, $accessMode, $exception, $message]) {
			// A waiting row of another file sits alongside: only file 10's is compared.
			$service = $this->serviceOver([self::bindingRow(9, 'pad-9', BindingService::STATE_PENDING_DELETE), $row]);
			try {
				$service->assertConsistentMapping(10, $padId, $accessMode);
				$this->assertNull($exception, $case . ': accepted');
			} catch (BindingException $e) {
				$this->assertSame([$exception, $message], [$e::class, $e->getMessage()], $case);
			}
		}
	}

	/**
	 * A file seen deleted for good that is opened was not deleted after all:
	 * its row is active again, and keeps its pad. Only its own row.
	 */
	public function testARowSeenDeletedWhoseFileIsOpenedIsActiveAgain(): void {
		$table = new InMemoryBindingTable([self::bindingRow(9, 'pad-9', BindingService::STATE_PENDING_DELETE), self::bindingRow(10, 'pad-a', BindingService::STATE_PENDING_DELETE)]);

		(new BindingService($table, new FixedClock(500)))->assertConsistentMapping(10, 'pad-a', BindingService::ACCESS_PUBLIC);

		$this->assertSame([9 => BindingService::STATE_PENDING_DELETE, 10 => BindingService::STATE_ACTIVE], array_column($table->rows, 'state', 'file_id'));
		$this->assertNull($table->rows[1]['deleted_at']);
	}

	/**
	 * An insert the database does not take - a row another request made at
	 * the same moment, or the database gone - is its own exception, with the
	 * database's error as the cause and no line of its own: every caller
	 * reports it.
	 */
	public function testAFailedInsertIsItsOwnErrorWithTheDatabasesCause(): void {
		$cause = new \RuntimeException('duplicate key value violates unique constraint');
		// An insert whose statement the database refuses.
		$qb = new class ($cause) implements IQueryBuilder {
			public function __construct(private \Throwable $cause) {
			}

			public function insert(string $table): self {
				return $this;
			}

			public function values(array $values): self {
				return $this;
			}

			public function createNamedParameter(mixed $value, mixed $type = null): string {
				return ':p';
			}

			public function executeStatement(): int {
				throw $this->cause;
			}
		};
		$db = new class ($qb) implements IDBConnection {
			public function __construct(private IQueryBuilder $qb) {
			}

			public function getQueryBuilder(): IQueryBuilder {
				return $this->qb;
			}

			public function escapeLikeParameter(string $param): string {
				return $param;
			}
		};

		try {
			(new BindingService($db, new FixedClock(500)))->createBinding(10, 'pad-a', BindingService::ACCESS_PUBLIC);
			$this->fail('The insert went through.');
		} catch (BindingNotCreatedException $e) {
			$this->assertSame($cause, $e->getPrevious());
		}
	}

	/** Whether the file's row names this pad: another pad, or no row at all, is not. */
	public function testTellsWhetherAFilesRowNamesAPad(): void {
		$service = $this->serviceOver([self::bindingRow(1, 'pad', BindingService::STATE_ACTIVE)]);

		$this->assertTrue($service->isBoundTo(1, 'pad'));
		$this->assertFalse($service->isBoundTo(1, 'other'), 'another pad');
		$this->assertFalse($service->isBoundTo(2, 'pad'), 'no row');
	}

	/**
	 * Asked for in a state, a pad's row counts only in that state: the
	 * original a copy recovers from, or the owner a migration collides
	 * with, is an active row, not one in a trash.
	 */
	public function testFindsAPadsRowInTheStateAskedFor(): void {
		$service = $this->serviceOver([self::bindingRow(5, 'pad-trashed', BindingService::STATE_PENDING_DELETE, BindingService::ACCESS_PROTECTED)]);

		$this->assertNull($service->findByPadId('pad-trashed', BindingService::STATE_ACTIVE));
		$this->assertEquals(
			new Binding(5, 'pad-trashed', BindingService::ACCESS_PROTECTED, BindingService::STATE_PENDING_DELETE, 100, 100),
			$service->findByPadId('pad-trashed'),
		);
		$this->assertNull($service->findByPadId('pad-other'));
	}

	/**
	 * Files seen deleted for good: their active rows wait for their pads to
	 * go, dated now, deleted_at and updated_at alike; a row that waits
	 * already keeps its dates, and the other rows are left alone. Files the
	 * file cache has again are active again, undated. Any number of files,
	 * in chunks the database takes.
	 */
	public function testFilesDeletedForGoodWaitForTheirPadsToGo(): void {
		$rows = array_map(static fn (int $id): array => self::bindingRow($id, 'pad-' . $id, BindingService::STATE_ACTIVE), range(1, 1200));
		$rows[1] = self::bindingRow(2, 'pad-2', BindingService::STATE_PENDING_DELETE);
		$table = new InMemoryBindingTable($rows);
		$service = new BindingService($table, new FixedClock(500));
		$dates = static fn (): array => array_map(static fn (array $row): array => [$row['state'], $row['deleted_at'], $row['updated_at']], array_intersect_key(array_column($table->rows, null, 'file_id'), [1 => 0, 2 => 0, 3 => 0, 1100 => 0]));
		$pending = BindingService::STATE_PENDING_DELETE;
		$active = BindingService::STATE_ACTIVE;

		$service->markGone([1, 2, 1100]);
		$this->assertSame([1 => [$pending, 500, 500], 2 => [$pending, 100, 100], 3 => [$active, null, 100], 1100 => [$pending, 500, 500]], $dates());

		$service->markGone(range(1, 1200));
		$this->assertSame([$pending], array_values(array_unique(array_column($table->rows, 'state'))), 'every chunk');

		$service->clearGone([2, 1100]);
		$this->assertSame([1 => [$pending, 500, 500], 2 => [$active, null, 500], 3 => [$pending, 500, 500], 1100 => [$active, null, 500]], $dates());

		$service->markGone([]);
		$service->clearGone([]);
	}

	/** The files of the rows on a storage, as the file cache has them. Nothing is marked by asking. */
	public function testTheFilesOfTheRowsOnAStorage(): void {
		$fileCache = [
			['fileid' => 1, 'storage' => 1, 'path' => 'files/a.pad'],
			['fileid' => 2, 'storage' => 1, 'path' => 'files/sub/b.pad'],
			['fileid' => 5, 'storage' => 2, 'path' => 'files/e.pad'],
		];
		$rows = array_map(static fn (int $id): array => self::bindingRow($id, 'pad-' . $id, BindingService::STATE_ACTIVE), [1, 2, 5, 6]);
		$table = new InMemoryBindingTable($rows, $fileCache);
		$service = new BindingService($table, new FixedClock(500));

		$this->assertSame([1, 2], $service->fileIdsOnStorage(1));
		$this->assertSame([5], $service->fileIdsOnStorage(2));
		$this->assertSame([], $service->fileIdsOnStorage(3));
		$this->assertSame([BindingService::STATE_ACTIVE], array_values(array_unique(array_column($table->rows, 'state'))));
	}

	/**
	 * The sweep of files gone for good asks the file cache through the
	 * binding table: rows of files seen deleted for good whose file is gone,
	 * past their grace, and never tried or tried long enough ago; the
	 * longest untouched first. Active rows are never taken.
	 */
	public function testTheSweepFindsWhatTheFileCacheNoLongerHas(): void {
		$row = static fn (int $fileId, string $state, ?int $seenAt, ?int $triedAt = null): array => ['deleted_at' => $seenAt, 'updated_at' => $triedAt ?? $seenAt ?? 100] + self::bindingRow($fileId, 'pad-' . $fileId, $state);
		$pending = BindingService::STATE_PENDING_DELETE;
		$table = new InMemoryBindingTable([
			$row(11, $pending, 300),
			$row(12, $pending, 200),
			$row(13, $pending, 100),
			$row(14, BindingService::STATE_ACTIVE, null),
			$row(15, $pending, 450),
			$row(16, $pending, 50, triedAt: 250),
			$row(17, $pending, 50, triedAt: 150),
			// An active row that kept a date is still no row seen deleted.
			$row(18, BindingService::STATE_ACTIVE, 50),
		], [
			['fileid' => 13, 'storage' => 1, 'path' => 'files/13.pad'],
		]);
		$service = new BindingService($table, new FixedClock(500));
		$found = static fn (array $rows): array => array_map(static fn (Binding $b): int => $b->fileId, $rows);

		$this->assertSame([17, 12, 11], $found($service->findGone(10, 400, 200)));
		$this->assertSame([17], $found($service->findGone(1, 400, 200)));
		$this->assertSame([17, 12, 16, 11, 15], $found($service->findGone(10, 500, 300)));
		$this->assertTrue($service->isFileGone(11));
		$this->assertFalse($service->isFileGone(13));
	}

	/**
	 * A file seen deleted for good that the file cache still has, seen at or
	 * before the time asked for, was not deleted: its row is active again,
	 * as many as asked for. One seen later waits, and so does one whose file
	 * is gone.
	 */
	public function testDeletionsThatDidNotHappenLeaveTheirRowsActive(): void {
		$row = static fn (int $fileId, int $seenAt): array => ['deleted_at' => $seenAt, 'updated_at' => $seenAt] + self::bindingRow($fileId, 'pad-' . $fileId, BindingService::STATE_PENDING_DELETE);
		$table = new InMemoryBindingTable([
			$row(1, 100),
			$row(2, 200),
			$row(3, 400),
			$row(4, 100),
			self::bindingRow(5, 'pad-e', BindingService::STATE_ACTIVE),
		], array_map(static fn (int $id): array => ['fileid' => $id, 'storage' => 1, 'path' => 'files/' . $id . '.pad'], [1, 2, 3, 5]));
		$service = new BindingService($table, new FixedClock(500));

		$this->assertSame(1, $service->clearStaleGone(300, 1));
		$this->assertSame(1, $service->clearStaleGone(300, 10));
		$this->assertSame(0, $service->clearStaleGone(300, 10));

		$active = BindingService::STATE_ACTIVE;
		$pending = BindingService::STATE_PENDING_DELETE;
		$this->assertSame([1 => $active, 2 => $active, 3 => $pending, 4 => $pending, 5 => $active], array_column($table->rows, 'state', 'file_id'));
	}

	/**
	 * A gone file's pad Etherpad refused: its row is touched, and the sweep
	 * passes it by until its next try; the date it was seen deleted stays.
	 * Another pad's row, or an active one, is not touched.
	 */
	public function testAPostponedFileWaitsForItsTry(): void {
		$row = static fn (int $fileId, string $state): array => ['deleted_at' => $state === BindingService::STATE_PENDING_DELETE ? 100 : null] + self::bindingRow($fileId, 'pad-' . $fileId, $state);
		$table = new InMemoryBindingTable([$row(1, BindingService::STATE_PENDING_DELETE), $row(2, BindingService::STATE_PENDING_DELETE), $row(3, BindingService::STATE_ACTIVE)]);
		$clock = new FixedClock(500);
		$service = new BindingService($table, $clock);

		$service->postponeGone(1, 'pad-1');
		$service->postponeGone(2, 'pad-other');
		$service->postponeGone(3, 'pad-3');

		$this->assertSame([[100, 500], [100, 100], [null, 100]], array_map(static fn (array $row): array => [$row['deleted_at'], $row['updated_at']], $table->rows));
		$this->assertSame([2], array_map(static fn (Binding $b): int => $b->fileId, $service->findGone(10, 500, 499)));
		$this->assertSame([2, 1], array_map(static fn (Binding $b): int => $b->fileId, $service->findGone(10, 500, 500)));
	}

	/** How many files wait for their pads to go, in one count. */
	public function testCountsTheRowsThatWait(): void {
		$service = $this->serviceOver([
			self::bindingRow(1, 'pad-a', BindingService::STATE_PENDING_DELETE),
			self::bindingRow(2, 'pad-b', BindingService::STATE_ACTIVE),
			self::bindingRow(3, 'pad-c', BindingService::STATE_PENDING_DELETE),
		]);

		$this->assertSame(2, $service->countPendingDeletes());
		$this->assertSame(0, $this->serviceOver([])->countPendingDeletes());
	}

	/** @param list<array<string,mixed>> $rows */
	private function serviceOver(array $rows): BindingService {
		return new BindingService(new InMemoryBindingTable($rows), new FixedClock(500));
	}

	/**
	 * Both predicates and the state belong in the statement. Without the pad
	 * id a delete by file id alone takes the row a concurrent rebind just
	 * won; without `state` it takes the row a trash left as pending_delete,
	 * the only record of a deletion still owed.
	 */
	public function testDeletingAnActiveBindingLeavesAnOwedDeletionAlone(): void {
		$table = new InMemoryBindingTable([
			self::bindingRow(4711, 'nc-owed', BindingService::STATE_PENDING_DELETE),
			self::bindingRow(4712, 'nc-abc', BindingService::STATE_ACTIVE),
		]);
		$service = new BindingService($table, new FixedClock(500));

		self::assertFalse($service->deleteActiveBinding(4711, 'nc-owed'), 'owed');
		self::assertFalse($service->deleteActiveBinding(4712, 'nc-other'), 'another pad');
		self::assertFalse($service->deleteActiveBinding(4713, 'nc-abc'), 'another file');
		self::assertTrue($service->deleteActiveBinding(4712, 'nc-abc'));
		self::assertSame([self::bindingRow(4711, 'nc-owed', BindingService::STATE_PENDING_DELETE)], $table->rows);
	}

	/**
	 * The conditional writes are all that stands between two flows that
	 * each take a row for theirs, so what proves them is the rows they
	 * leave alone. File 2 names the pad the first call asks for: without
	 * the file in the statement that call takes file 2's row, without the
	 * pad it takes file 1's.
	 */
	public function testRebindMovesARowOnlyWhileItNamesThatPadInThatState(): void {
		$table = new InMemoryBindingTable([
			self::bindingRow(1, 'old', BindingService::STATE_PENDING_DELETE),
			self::bindingRow(2, 'other', BindingService::STATE_PENDING_DELETE),
		]);
		$service = new BindingService($table, new FixedClock(500));
		$before = $table->rows;

		self::assertFalse($service->rebind(1, 'other', BindingService::STATE_PENDING_DELETE, 'new', BindingService::STATE_ACTIVE), 'another pad');
		self::assertFalse($service->rebind(1, 'old', BindingService::STATE_ACTIVE, 'new', BindingService::STATE_ACTIVE), 'another state');
		self::assertSame($before, $table->rows);

		self::assertTrue($service->rebind(1, 'old', BindingService::STATE_PENDING_DELETE, 'new', BindingService::STATE_ACTIVE));
		self::assertSame([
			['file_id' => 1, 'pad_id' => 'new', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 500],
			self::bindingRow(2, 'other', BindingService::STATE_PENDING_DELETE),
		], $table->rows);
	}

	/** A row that comes to wait is dated from now; one that stops waiting is undated. */
	public function testTransitionDatesARowThatComesToWait(): void {
		$table = new InMemoryBindingTable([self::bindingRow(1, 'pad', BindingService::STATE_ACTIVE)]);
		$service = new BindingService($table, new FixedClock(500));

		self::assertTrue($service->transition(1, 'pad', BindingService::STATE_ACTIVE, BindingService::STATE_PENDING_DELETE));
		self::assertSame(
			['file_id' => 1, 'pad_id' => 'pad', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE, 'deleted_at' => 500, 'updated_at' => 500],
			$table->rows[0],
		);

		self::assertTrue($service->transition(1, 'pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_ACTIVE));
		self::assertNull($table->rows[0]['deleted_at']);
	}

	/** The same three conditions, for the sweep's delete of a row whose pad is gone. */
	public function testDeleteInStateRemovesARowOnlyWhileItNamesThatPadInThatState(): void {
		$table = new InMemoryBindingTable([
			self::bindingRow(1, 'old', BindingService::STATE_PENDING_DELETE),
			self::bindingRow(2, 'other', BindingService::STATE_PENDING_DELETE),
		]);
		$service = new BindingService($table, new FixedClock(500));
		$before = $table->rows;

		self::assertFalse($service->deleteInState(1, 'other', BindingService::STATE_PENDING_DELETE), 'another pad');
		self::assertFalse($service->deleteInState(1, 'old', BindingService::STATE_ACTIVE), 'another state');
		self::assertSame($before, $table->rows);

		self::assertTrue($service->deleteInState(1, 'old', BindingService::STATE_PENDING_DELETE));
		self::assertSame([self::bindingRow(2, 'other', BindingService::STATE_PENDING_DELETE)], $table->rows);
	}

	/**
	 * A row that stays waiting keeps the date its file was seen deleted.
	 * Only updated_at moves, which puts it at the back of the queue.
	 */
	public function testARowThatStaysOwedKeepsItsDate(): void {
		$table = new InMemoryBindingTable([self::bindingRow(1, 'pad', BindingService::STATE_PENDING_DELETE)]);
		$service = new BindingService($table, new FixedClock(500));

		self::assertTrue($service->transition(1, 'pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_PENDING_DELETE));

		self::assertSame(
			['file_id' => 1, 'pad_id' => 'pad', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE, 'deleted_at' => 100, 'updated_at' => 500],
			$table->rows[0],
		);
	}

	/** @return array<string,mixed> */
	private static function bindingRow(int $fileId, string $padId, string $state, string $accessMode = BindingService::ACCESS_PUBLIC): array {
		// Dated only while it waits, as the table holds it: leaving that state clears the date.
		$deletedAt = $state === BindingService::STATE_PENDING_DELETE ? 100 : null;
		return ['file_id' => $fileId, 'pad_id' => $padId, 'access_mode' => $accessMode, 'state' => $state, 'deleted_at' => $deletedAt, 'updated_at' => 100];
	}
}
