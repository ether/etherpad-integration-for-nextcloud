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
	 * Two requests that find a row seen deleted - an open beside a sync, or
	 * the sweep taking stale marks back - race to make it active. The one
	 * that loses reads the row again, and it is active: no refusal.
	 */
	public function testARequestThatLosesTheRaceToTakeARowBackAcceptsIt(): void {
		$table = new InMemoryBindingTable([self::bindingRow(10, 'pad-a', BindingService::STATE_PENDING_DELETE)]);
		$service = new class($table, new FixedClock(500)) extends BindingService {
			public function transition(int $fileId, string $padId, string $from, string $to): bool {
				// The other request is first.
				parent::transition($fileId, $padId, $from, $to);
				return false;
			}
		};

		$service->assertConsistentMapping(10, 'pad-a', BindingService::ACCESS_PUBLIC);

		$this->assertSame(BindingService::STATE_ACTIVE, $table->rows[0]['state']);
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

	/**
	 * Of the files a removal reported, only those the file cache has
	 * nothing of are marked: a removal under an id that is not the file's
	 * leaves a file that is still there alone. Any number, in chunks.
	 */
	public function testOnlyFilesTheFileCacheNoLongerHasAreMarked(): void {
		$rows = array_map(static fn (int $id): array => self::bindingRow($id, 'pad-' . $id, BindingService::STATE_ACTIVE), range(1, 1200));
		$table = new InMemoryBindingTable($rows, [
			['fileid' => 2, 'storage' => 1, 'path' => 'files/still-here.pad'],
			['fileid' => 1100, 'storage' => 1, 'path' => 'files/also-here.pad'],
		]);
		$service = new BindingService($table, new FixedClock(500));

		$marked = $service->markIfGone([...range(1, 1200), 5000]);

		$pending = array_keys(array_filter(array_column($table->rows, 'state', 'file_id'), static fn (string $state): bool => $state === BindingService::STATE_PENDING_DELETE));
		$this->assertSame(array_values(array_diff(range(1, 1200), [2, 1100])), $pending);
		$this->assertSame($pending, $marked, 'what it marked: no file without a row, none still there');
		$service->markIfGone([]);
	}

	/** Where the file cache has a file, as a removal reports it; nothing for one it does not have. */
	public function testWhereTheFileCacheHasAFile(): void {
		$service = new BindingService(new InMemoryBindingTable([], [['fileid' => 7, 'storage' => 3, 'path' => 'files/Sub']]), new FixedClock(500));

		$this->assertSame([3, 'files/Sub'], $service->placeOf(7));
		$this->assertNull($service->placeOf(8));
	}

	/**
	 * The files of a group's pads, a row owed a delete among them, in as
	 * few queries as five hundred pads allow; none at all for a pad no row
	 * names - a pad of no file.
	 */
	public function testTheFilesOfManyPads(): void {
		$rows = [self::bindingRow(9, 'g.A$b', BindingService::STATE_ACTIVE), self::bindingRow(7, 'g.A$a', BindingService::STATE_PENDING_DELETE)];
		for ($i = 0; $i < 600; $i++) {
			$rows[] = self::bindingRow(1000 + $i, 'g.B$' . $i, BindingService::STATE_ACTIVE);
		}
		$service = new BindingService(new InMemoryBindingTable($rows), new FixedClock(500));
		$many = array_map(static fn (int $i): string => 'g.B$' . $i, range(0, 599));

		$this->assertSame([7, 9], $service->filesOfPads(['g.A$b', 'g.A$a', 'g.A$b']));
		$this->assertSame(range(1000, 1599), $service->filesOfPads($many));
		$this->assertNull($service->filesOfPads([...$many, 'g.B$unbound']));
		$this->assertNull($service->filesOfPads(['g.A$a', 'g.A$elsewhere']));
		$this->assertSame([], $service->filesOfPads([]));
	}

	public function testWhereTheFileCacheHasManyFiles(): void {
		$service = new BindingService(new InMemoryBindingTable([], [
			['fileid' => 7, 'storage' => 3, 'path' => 'files/Notes.pad'],
			['fileid' => 9, 'storage' => 5, 'path' => 'trash/Other.pad.d1'],
		]), new FixedClock(500));

		$this->assertSame([7 => [3, 'files/Notes.pad'], 9 => [5, 'trash/Other.pad.d1']], $service->placesOf([9, 8, 7]));
		$this->assertSame([], $service->placesOf([]));
	}

	/**
	 * In Files is where a user sees it, through a mount of their own: a
	 * home, an older team folder on the root storage, a team folder of its
	 * own storage, an external storage. A trash is beside those paths; a
	 * share's mount, left behind rooted at the shared file in its owner's
	 * trash, counts for nothing.
	 *
	 * @return iterable<string,array{int,string,bool}>
	 */
	public static function places(): iterable {
		yield 'in a user\'s files' => [3, 'files/Notes.pad', true];
		yield 'in a user\'s trash, shared before' => [3, 'files_trashbin/files/Notes.pad.d1', false];
		yield 'in a team folder on the root storage' => [1, '__groupfolders/7/Notes.pad', true];
		yield 'in its trash' => [1, '__groupfolders/trash/7/Notes.pad.d1', false];
		yield 'in a team folder of its own storage' => [9, 'files/Notes.pad', true];
		yield 'in that one\'s trash' => [9, 'trash/Notes.pad.d1', false];
		yield 'in a folder called trash on an external storage' => [5, 'trash/Notes.pad', true];
		yield 'on a storage no user has a mount of' => [4, 'files/Notes.pad', false];
		yield 'through a mount from before its provider was kept' => [6, 'files/Notes.pad', true];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('places')]
	public function testWhetherAUserSeesAFileInFiles(int $storage, string $path, bool $inFiles): void {
		$root = static fn (int $fileId, int $storage, string $path): array => ['fileid' => $fileId, 'storage' => $storage, 'path' => $path, 'path_hash' => md5($path)];
		$service = new BindingService(new InMemoryBindingTable([], [
			$root(1, 3, ''),
			$root(2, 3, 'files_trashbin/files/Notes.pad.d1'),
			$root(3, 1, '__groupfolders/7'),
			$root(4, 9, 'files'),
			$root(5, 5, ''),
			$root(6, 6, ''),
		], [
			['storage_id' => 3, 'root_id' => 1, 'mount_point' => '/alice/', 'mount_provider_class' => 'OC\\Files\\Mount\\LocalHomeMountProvider'],
			['storage_id' => 3, 'root_id' => 2, 'mount_point' => '/bob/files/Notes.pad/', 'mount_provider_class' => 'OCA\\Files_Sharing\\MountProvider'],
			['storage_id' => 1, 'root_id' => 3, 'mount_point' => '/alice/files/Old Team/', 'mount_provider_class' => 'OCA\\GroupFolders\\Mount\\MountProvider'],
			['storage_id' => 9, 'root_id' => 4, 'mount_point' => '/alice/files/Team/', 'mount_provider_class' => 'OCA\\GroupFolders\\Mount\\MountProvider'],
			['storage_id' => 5, 'root_id' => 5, 'mount_point' => '/alice/files/SMB/', 'mount_provider_class' => 'OCA\\Files_External\\Config\\ConfigAdapter'],
			['storage_id' => 6, 'root_id' => 6, 'mount_point' => '/carol/', 'mount_provider_class' => null],
		]), new FixedClock(500));

		$this->assertSame($inFiles, $service->isInFiles($storage, $path));
	}

	/**
	 * Only the mounts rooted at the file or above it are read, a few of
	 * them: a storage holds one for every user of every team folder on it,
	 * and the right one need not be among the first.
	 */
	public function testReadsOnlyTheMountsAboveTheFile(): void {
		$fileCache = [];
		$mounts = [];
		foreach ([...range(1, 25), 30] as $folder) {
			$fileCache[] = ['fileid' => $folder, 'storage' => 1, 'path' => '__groupfolders/' . $folder, 'path_hash' => md5('__groupfolders/' . $folder)];
			$mounts[] = ['storage_id' => 1, 'root_id' => $folder, 'mount_point' => '/alice/files/Team ' . $folder . '/', 'mount_provider_class' => 'OCA\\GroupFolders\\Mount\\MountProvider'];
		}
		$service = new BindingService(new InMemoryBindingTable([], $fileCache, $mounts), new FixedClock(500));

		$this->assertTrue($service->isInFiles(1, '__groupfolders/30/Notes.pad'));
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
	 * won; without `state` it takes a row whose file was seen deleted for
	 * good, the only record of the pad the sweep has yet to delete.
	 */
	public function testDeletingAnActiveBindingLeavesARowSeenDeletedAlone(): void {
		$table = new InMemoryBindingTable([
			self::bindingRow(4711, 'nc-gone', BindingService::STATE_PENDING_DELETE),
			self::bindingRow(4712, 'nc-abc', BindingService::STATE_ACTIVE),
		]);
		$service = new BindingService($table, new FixedClock(500));

		self::assertFalse($service->deleteActiveBinding(4711, 'nc-gone'), 'seen deleted');
		self::assertFalse($service->deleteActiveBinding(4712, 'nc-other'), 'another pad');
		self::assertFalse($service->deleteActiveBinding(4713, 'nc-abc'), 'another file');
		self::assertTrue($service->deleteActiveBinding(4712, 'nc-abc'));
		self::assertSame([self::bindingRow(4711, 'nc-gone', BindingService::STATE_PENDING_DELETE)], $table->rows);
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
	 * A row that stays pending_delete keeps the date its file was seen
	 * deleted for good, which its grace runs from. Only updated_at moves.
	 */
	public function testARowThatStaysSeenDeletedKeepsItsDate(): void {
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
