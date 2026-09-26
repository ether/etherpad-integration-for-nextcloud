<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingNotCreatedException;
use OCA\EtherpadNextcloud\Exception\BindingMismatchException;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\FileLocation;
use OCA\EtherpadNextcloud\Service\SweptBinding;
use OCA\EtherpadNextcloud\Service\WaitingBinding;
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
			// Waiting is its own kind, so the one who opens the file is told it is on its way back.
			'a deletion owed' => [self::bindingRow(10, 'pad-a', BindingService::STATE_PENDING_DELETE), 'pad-a', BindingService::ACCESS_PUBLIC, WaitingBindingException::class, 'Pad binding is not active.'],
			'a restore undecided' => [self::bindingRow(10, 'pad-a', BindingService::STATE_RESTORE_PENDING), 'pad-a', BindingService::ACCESS_PUBLIC, WaitingBindingException::class, 'Pad binding is not active.'],
			// No sweep takes up a state it does not know, so nothing waits for one either.
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
	 * Both kinds of waiting row come with the path their file has now, and a
	 * row whose file is gone for good has no file cache row to join: left,
	 * not inner. A deletion owed is aged by when the trash recorded it, an
	 * undecided restore by when it last changed, and the row carries that
	 * time along.
	 */
	public function testWaitingRowsComeAgedWithTheirFilesPath(): void {
		$kinds = [
			'restores' => [BindingService::STATE_RESTORE_PENDING, 'b.updated_at', static fn (BindingService $s): array => $s->findRestorePendingByAge(3600, 86400, 50)],
			'deletions owed' => [BindingService::STATE_PENDING_DELETE, 'b.deleted_at', static fn (BindingService $s): array => $s->findPendingDeleteByAge(3600, 86400, 50)],
		];
		foreach ($kinds as $kind => [$state, $ageColumn, $find]) {
			$qb = new BindingServiceTestQueryBuilder([['file_id' => 10, 'pad_id' => 'pad-a', 'state' => $state, 'file_path' => null, 'waiting_since' => 90000]]);

			$rows = $find($this->buildServiceWithQueryBuilder($qb, 100000));

			$this->assertEquals([new WaitingBinding(10, 'pad-a', $state, null, 90000)], $rows, $kind);
			// Every column a WaitingBinding is read from; one left out would read as a row without it.
			$this->assertSame(['b.file_id', 'b.pad_id', 'b.state'], $qb->selected, $kind);
			$this->assertSame([['fc.path', 'file_path'], [$ageColumn, 'waiting_since']], $qb->aliases, $kind);
			$this->assertSame([['b', 'filecache', 'fc', ['eq', 'b.file_id', 'fc.fileid']]], $qb->leftJoins, $kind);
			$this->assertSame(50, $qb->maxResults, $kind);
			$this->assertSame([['eq', 'b.state', 'param1'], ['lte', $ageColumn, 'param2'], ['gt', $ageColumn, 'param3']], $qb->conditions, $kind);
			$this->assertSame([
				['param1', $state, null],
				['param2', 96400, IQueryBuilder::PARAM_INT],
				['param3', 13600, IQueryBuilder::PARAM_INT],
			], $qb->parameters, $kind);
		}
	}

	/**
	 * Unaged, there is no condition on the date at all, so a row that never
	 * had one is reached too; and a limit that makes no sense still reaches
	 * one row.
	 */
	public function testAnUnagedRunComparesNoDate(): void {
		$kinds = [
			'restores' => static fn (BindingService $s): array => $s->findRestorePendingByAge(-1, null, 0),
			'deletions owed' => static fn (BindingService $s): array => $s->findPendingDeleteByAge(-1, null, 0),
		];
		foreach ($kinds as $kind => $find) {
			$qb = new BindingServiceTestQueryBuilder([]);

			$find($this->buildServiceWithQueryBuilder($qb, 100000));

			$this->assertSame([['eq', 'b.state', 'param1']], $qb->conditions, $kind);
			$this->assertSame(1, $qb->maxResults, $kind);
		}
	}

	/**
	 * Files seen leaving Files mark their rows once: a row marked already
	 * keeps its first mark, and the other rows are left alone. Back in
	 * Files, they lose it. Any number of files, in chunks the database
	 * takes.
	 */
	public function testFilesLeavingFilesMarkTheirRowsOnce(): void {
		$rows = array_map(static fn (int $id): array => self::bindingRow($id, 'pad-' . $id, BindingService::STATE_ACTIVE), range(1, 1200));
		$rows[1]['trashed_at'] = 300;
		$table = new InMemoryBindingTable($rows);
		$service = new BindingService($table, new FixedClock(500));

		$service->markTrashed([1, 2, 1100]);
		$this->assertSame([1 => 500, 2 => 300, 3 => null, 1100 => 500], array_intersect_key(array_column($table->rows, 'trashed_at', 'file_id'), [1 => 0, 2 => 0, 3 => 0, 1100 => 0]));

		$service->markTrashed(range(1, 1200));
		$this->assertSame(array_fill(0, 1200, 500), array_map(static fn (?int $at): int => $at === 300 ? 500 : (int)$at, array_column($table->rows, 'trashed_at')), 'every chunk');

		$service->clearTrashed([2, 1100]);
		$this->assertSame([1 => 500, 2 => null, 3 => 500, 1100 => null], array_intersect_key(array_column($table->rows, 'trashed_at', 'file_id'), [1 => 0, 2 => 0, 3 => 0, 1100 => 0]));

		$service->markTrashed([]);
		$service->clearTrashed([]);
	}

	/**
	 * The files of the rows under a folder, as the file cache has them: on
	 * the folder's storage, below its path and not beside it. A `_` in the
	 * folder's name is a character, not any one. A folder at the root of
	 * its storage has the whole storage, and so does a storage asked for as
	 * a whole; an id the file cache does not know has nothing. Nothing is
	 * marked by asking.
	 */
	public function testTheFilesUnderAFolderAndOnAStorage(): void {
		$fileCache = [
			['fileid' => 100, 'storage' => 1, 'path' => 'files/Te_m'],
			['fileid' => 101, 'storage' => 1, 'path' => ''],
			['fileid' => 1, 'storage' => 1, 'path' => 'files/Te_m/a.pad'],
			['fileid' => 2, 'storage' => 1, 'path' => 'files/Te_m/sub/b.pad'],
			['fileid' => 3, 'storage' => 1, 'path' => 'files/Te_mwork/c.pad'],
			['fileid' => 4, 'storage' => 1, 'path' => 'files/Team/d.pad'],
			['fileid' => 5, 'storage' => 2, 'path' => 'files/Te_m/e.pad'],
			['fileid' => 6, 'storage' => 1, 'path' => 'files/f.pad'],
		];
		$rows = array_map(static fn (int $id): array => self::bindingRow($id, 'pad-' . $id, BindingService::STATE_ACTIVE), [1, 2, 3, 4, 5, 6]);
		$table = new InMemoryBindingTable($rows, $fileCache);
		$service = new BindingService($table, new FixedClock(500));

		$this->assertSame([1, 2], $service->fileIdsUnder(100), 'the folder');
		$this->assertSame([1, 2, 3, 4, 6], $service->fileIdsUnder(101), 'the storage root');
		$this->assertSame([5], $service->fileIdsOnStorage(2), 'a storage');
		$this->assertSame([], $service->fileIdsUnder(999), 'an unknown folder');
		$this->assertSame(array_fill(0, 6, null), array_column($table->rows, 'trashed_at'));
	}

	/**
	 * The sweep of files gone for good asks the file cache through the
	 * binding table. Marked and gone: the earliest marked first, active rows
	 * only. The pass: active rows after the cursor whose file the file cache
	 * has, in id order, each with its file's path.
	 */
	public function testTheSweepFindsWhatTheFileCacheNoLongerHas(): void {
		$row = static fn (int $id, int $fileId, string $state, ?int $trashedAt): array => ['id' => $id, 'trashed_at' => $trashedAt] + self::bindingRow($fileId, 'pad-' . $fileId, $state);
		$table = new InMemoryBindingTable([
			$row(1, 11, BindingService::STATE_ACTIVE, 300),
			$row(2, 12, BindingService::STATE_ACTIVE, 200),
			$row(3, 13, BindingService::STATE_ACTIVE, 100),
			$row(4, 14, BindingService::STATE_ACTIVE, null),
			$row(5, 15, BindingService::STATE_PENDING_DELETE, 50),
			$row(6, 16, BindingService::STATE_ACTIVE, null),
			$row(7, 17, BindingService::STATE_PENDING_DELETE, null),
		], [
			['fileid' => 13, 'storage' => 1, 'path' => 'files_trashbin/files/13.pad.d100'],
			['fileid' => 16, 'storage' => 1, 'path' => 'files/16.pad'],
			['fileid' => 17, 'storage' => 1, 'path' => 'files/17.pad'],
			['fileid' => 99, 'storage' => 1, 'path' => 'files/99.pad'],
		]);
		$service = new BindingService($table, new FixedClock(500));
		$pass = static fn (array $swept): array => array_map(static fn (SweptBinding $s): array => [$s->id, $s->binding->fileId, $s->filePath], $swept);

		$this->assertSame([12, 11], array_map(static fn (Binding $b): int => $b->fileId, $service->findMarkedGone(10)));
		$this->assertSame([12], array_map(static fn (Binding $b): int => $b->fileId, $service->findMarkedGone(1)));
		$this->assertSame([[3, 13, 'files_trashbin/files/13.pad.d100'], [6, 16, 'files/16.pad']], $pass($service->findActiveWithFileAfter(0, 10)));
		$this->assertSame([[3, 13, 'files_trashbin/files/13.pad.d100']], $pass($service->findActiveWithFileAfter(0, 1)));
		$this->assertSame([[6, 16, 'files/16.pad']], $pass($service->findActiveWithFileAfter(3, 10)));
		$this->assertSame([], $service->findActiveWithFileAfter(6, 10));
		$this->assertTrue($service->isFileGone(11));
		$this->assertFalse($service->isFileGone(13));
		$this->assertSame([11, 14], array_map(static fn (Binding $b): int => $b->fileId, $service->findActiveGone([11, 13, 14, 15, 16])));
	}

	/**
	 * A gone file's pad Etherpad refused: its mark moves on to the next try,
	 * and the file is not found gone till then. An unmarked row stays so.
	 */
	public function testAPostponedFileWaitsForItsTry(): void {
		$table = new InMemoryBindingTable([
			['trashed_at' => 300] + self::bindingRow(1, 'pad-a', BindingService::STATE_ACTIVE),
			['trashed_at' => 300] + self::bindingRow(2, 'pad-b', BindingService::STATE_ACTIVE),
			self::bindingRow(3, 'pad-c', BindingService::STATE_ACTIVE),
		]);
		$clock = new FixedClock(500);
		$service = new BindingService($table, $clock);

		$service->postponeGone(1, 1000);
		$service->postponeGone(3, 1000);

		$this->assertSame([1000, 300, null], array_column($table->rows, 'trashed_at'));
		$this->assertSame([2], array_map(static fn (Binding $b): int => $b->fileId, $service->findMarkedGone(10)));
		$clock->advance(500);
		$this->assertSame([2, 1], array_map(static fn (Binding $b): int => $b->fileId, $service->findMarkedGone(10)));
	}

	/** @param list<array<string,mixed>> $rows */
	private function serviceOver(array $rows): BindingService {
		return new BindingService(new InMemoryBindingTable($rows), new FixedClock(500));
	}

	private function buildServiceWithQueryBuilder(BindingServiceTestQueryBuilder $qb, int $now): BindingService {
		$db = new class ($qb) implements IDBConnection {
			public function __construct(private BindingServiceTestQueryBuilder $qb) {
			}

			public function getQueryBuilder(): IQueryBuilder {
				return $this->qb;
			}

			public function escapeLikeParameter(string $param): string {
				return addcslashes($param, '\\_%');
			}
		};

		return new BindingService($db, new FixedClock($now));
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
			['file_id' => 1, 'pad_id' => 'new', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 500, 'trashed_at' => null],
			self::bindingRow(2, 'other', BindingService::STATE_PENDING_DELETE),
		], $table->rows);
	}

	/**
	 * deleted_at says the file is in the trash. A restore that has to wait
	 * has its file back, so the date goes, whichever way the row got there.
	 */
	public function testARestoreLeftWaitingIsNotDatedAsDeleted(): void {
		$table = new InMemoryBindingTable([self::bindingRow(1, 'pad', BindingService::STATE_PENDING_DELETE)]);
		$service = new BindingService($table, new FixedClock(500));

		self::assertTrue($service->transition(1, 'pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_RESTORE_PENDING));

		self::assertNull($table->rows[0]['deleted_at']);
	}

	/** Back in the trash is a deletion owed again, dated from now. */
	public function testTransitionToPendingDeleteDatesTheDeletionAnew(): void {
		$table = new InMemoryBindingTable([self::bindingRow(1, 'pad', BindingService::STATE_RESTORE_PENDING)]);
		$service = new BindingService($table, new FixedClock(500));

		self::assertTrue($service->transition(1, 'pad', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_PENDING_DELETE));

		self::assertSame(
			['file_id' => 1, 'pad_id' => 'pad', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE, 'deleted_at' => 500, 'updated_at' => 500, 'trashed_at' => null],
			$table->rows[0],
		);
	}

	/** The same three conditions, for the recheck's delete of a row whose pad is gone. */
	public function testDeleteInStateRemovesARowOnlyWhileItNamesThatPadInThatState(): void {
		$table = new InMemoryBindingTable([
			self::bindingRow(1, 'old', BindingService::STATE_RESTORE_PENDING),
			self::bindingRow(2, 'other', BindingService::STATE_RESTORE_PENDING),
		]);
		$service = new BindingService($table, new FixedClock(500));
		$before = $table->rows;

		self::assertFalse($service->deleteInState(1, 'other', BindingService::STATE_RESTORE_PENDING), 'another pad');
		self::assertFalse($service->deleteInState(1, 'old', BindingService::STATE_ACTIVE), 'another state');
		self::assertSame($before, $table->rows);

		self::assertTrue($service->deleteInState(1, 'old', BindingService::STATE_RESTORE_PENDING));
		self::assertSame([self::bindingRow(2, 'other', BindingService::STATE_RESTORE_PENDING)], $table->rows);
	}

	/**
	 * Both figures in one grouped query, and a state with no rows is a
	 * zero rather than a missing key.
	 */
	public function testCountsTheWaitingRowsOfEitherKindInOneQuery(): void {
		$qb = new BindingServiceTestQueryBuilder([
			['state' => BindingService::STATE_ACTIVE, 'cnt' => '9'],
			['state' => BindingService::STATE_PENDING_DELETE, 'cnt' => '3'],
		]);

		$counts = $this->buildServiceWithQueryBuilder($qb, 100000)->countWaiting();

		$this->assertSame(['pending_delete_count' => 3, 'restore_pending_count' => 0], $counts);
		$this->assertSame(['state'], $qb->groupBy);
	}

	/**
	 * A sweep asks for owed deletions by where the file is, so rows that
	 * wait on a trash cannot crowd out the ones that can go: gone for good
	 * has no file cache row, a user's trash is under files_trashbin/, and
	 * the rest is everything else that is still there.
	 */
	public function testOwedDeletionsCanBeAskedForByWhereTheFileIs(): void {
		$expected = [
			[FileLocation::Gone, [['eq', 'b.state', 'param1'], ['isNull', 'fc.fileid']], []],
			[FileLocation::InUserTrash, [['eq', 'b.state', 'param1'], 'fc.path LIKE param2'], ['files\\_trashbin/%']],
			[
				FileLocation::Elsewhere,
				// The negated like(), which carries the ESCAPE on every database; notLike() does not on SQLite and Oracle.
				[['eq', 'b.state', 'param1'], ['isNotNull', 'fc.fileid'], 'NOT (fc.path LIKE param2)', 'NOT (fc.path LIKE param3)'],
				// A team folder's trash on the root storage waits for that trash, not for a turn.
				['files\\_trashbin/%', '\\_\\_groupfolders/trash/%'],
			],
		];
		foreach ($expected as [$fileLocation, $conditions, $patterns]) {
			$qb = new BindingServiceTestQueryBuilder([]);
			$this->buildServiceWithQueryBuilder($qb, 100000)->findPendingDeleteByAge(0, null, 50, $fileLocation);

			$this->assertSame($conditions, $qb->conditions, $fileLocation->name);
			// Escaped: `_` is LIKE's wildcard for any one character.
			$this->assertSame($patterns, array_map(static fn (array $p): mixed => $p[1], array_slice($qb->parameters, 1)), $fileLocation->name);
			// Aged by deleted_at, taken by updated_at: a row that waits again goes to the back.
			$this->assertSame([['b.updated_at', 'ASC']], $qb->orderedBy, $fileLocation->name);
		}
	}

	/**
	 * A row that stays owed keeps the date it became owed: its age decides
	 * how often a sweep tries it. Only updated_at moves, which puts it at
	 * the back of the queue.
	 */
	public function testARowThatStaysOwedKeepsItsDate(): void {
		$table = new InMemoryBindingTable([self::bindingRow(1, 'pad', BindingService::STATE_PENDING_DELETE)]);
		$service = new BindingService($table, new FixedClock(500));

		self::assertTrue($service->transition(1, 'pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_PENDING_DELETE));

		self::assertSame(
			['file_id' => 1, 'pad_id' => 'pad', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE, 'deleted_at' => 100, 'updated_at' => 500, 'trashed_at' => null],
			$table->rows[0],
		);
	}

	/** @return array<string,mixed> */
	private static function bindingRow(int $fileId, string $padId, string $state, string $accessMode = BindingService::ACCESS_PUBLIC): array {
		// Dated only as a deletion owed, as the table holds it: leaving that state clears the date.
		$deletedAt = $state === BindingService::STATE_PENDING_DELETE ? 100 : null;
		return ['file_id' => $fileId, 'pad_id' => $padId, 'access_mode' => $accessMode, 'state' => $state, 'deleted_at' => $deletedAt, 'updated_at' => 100, 'trashed_at' => null];
	}

}

class BindingServiceTestQueryBuilder implements IQueryBuilder {
	/** @var array<int,array{string,mixed,int|null}> */
	public array $parameters = [];
	/** @var array<int,array<int,string>|string> */
	public array $conditions = [];
	public int $maxResults = 0;
	private int $parameterCounter = 0;
	/** @var list<string> */
	public array $selected = [];
	/** @var list<array{string,string}> */
	public array $aliases = [];
	/** @var list<array{string,string,string,mixed}> */
	public array $leftJoins = [];
	/** @var list<string> */
	public array $groupBy = [];
	/** @var list<array{string,string}> */
	public array $orderedBy = [];

	/** @param array<int,array<string,mixed>> $rows */
	public function __construct(private array $rows) {
	}

	public function select(string ...$select): self {
		$this->selected = array_values($select);
		return $this;
	}

	public function selectAlias(string $select, string $alias): self {
		$this->aliases[] = [$select, $alias];
		return $this;
	}

	public function from(string $table, ?string $alias = null): self {
		return $this;
	}

	public function createFunction(string $call): string {
		return $call;
	}

	public function groupBy(string $column): self {
		$this->groupBy[] = $column;
		return $this;
	}

	public function leftJoin(string $fromAlias, string $join, string $alias, mixed $condition): self {
		$this->leftJoins[] = [$fromAlias, $join, $alias, $condition];
		return $this;
	}

	public function where(mixed $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	public function andWhere(mixed $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	public function orderBy(string $field, string $direction): self {
		$this->orderedBy[] = [$field, $direction];
		return $this;
	}

	public function setMaxResults(int $maxResults): self {
		$this->maxResults = $maxResults;
		return $this;
	}

	public function createNamedParameter(mixed $value, ?int $type = null): string {
		$name = 'param' . ++$this->parameterCounter;
		$this->parameters[] = [$name, $value, $type];
		return $name;
	}

	public function expr(): BindingServiceTestExpressionBuilder {
		return new BindingServiceTestExpressionBuilder();
	}

	public function executeQuery(): BindingServiceTestResult {
		return new BindingServiceTestResult($this->rows);
	}
}

class BindingServiceTestExpressionBuilder {
	/** @return array{string,string,string} */
	public function eq(string $field, string $parameter): array {
		return ['eq', $field, $parameter];
	}

	/** @return array{string,string,string} */
	public function lte(string $field, string $parameter): array {
		return ['lte', $field, $parameter];
	}

	/** @return array{string,string,string} */
	public function gt(string $field, string $parameter): array {
		return ['gt', $field, $parameter];
	}

	/** @return array{string,string} */
	public function isNull(string $field): array {
		return ['isNull', $field];
	}

	/** @return array{string,string} */
	public function isNotNull(string $field): array {
		return ['isNotNull', $field];
	}

	/** SQL, as the real one returns it: the query negates it inside a function. */
	public function like(string $field, string $parameter): string {
		return $field . ' LIKE ' . $parameter;
	}
}

class BindingServiceTestResult {
	/** @param array<int,array<string,mixed>> $rows */
	public function __construct(private array $rows) {
	}

	/** @return array<int,array<string,mixed>> */
	public function fetchAll(): array {
		return $this->rows;
	}

	public function closeCursor(): void {
	}
}
