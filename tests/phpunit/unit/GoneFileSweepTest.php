<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\GoneFileSweep;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The sweep of files gone for good, over the binding table and file cache
 * as the database would answer: which pads go, which stay, and which rows
 * get or lose their mark.
 */
class GoneFileSweepTest extends TestCase {
	private InMemoryBindingTable $db;
	private FixedClock $clock;
	/** @var array<string,int|bool> the app config the sweep reads and writes */
	private array $config = [];
	/** @var list<string> pads deleted in Etherpad */
	private array $deletedPads = [];
	/** @var array<string,\Throwable> what deleting a pad throws */
	private array $padErrors = [];
	/** @var list<array{string,string,int|null}> level, message and file of each line logged */
	private array $lines = [];
	/** @var (\Closure(): void)|null what deleting a pad takes, besides */
	private ?\Closure $onDelete = null;

	protected function setUp(): void {
		$this->clock = new FixedClock();
		$this->config = ['delete_on_trash' => true, 'cursor' => 0];
		$this->deletedPads = [];
		$this->padErrors = [];
		$this->lines = [];
	}

	/**
	 * A file marked as leaving Files and gone from the file cache is gone for
	 * good: its pad and row go, in one run, with a line naming the pad. One
	 * still in a trash keeps its pad, and so does one gone without a mark.
	 */
	public function testAFileSeenLeavingAndGoneTakesItsPad(): void {
		$this->table(
			[self::row(1, 11, 'pad-gone', trashedAt: 100), self::row(2, 12, 'pad-in-trash', trashedAt: 100), self::row(3, 13, 'pad-unmarked')],
			[self::cached(12, 'files_trashbin/files/Notes.pad.d100')],
		);

		$this->sweep();

		$this->assertSame(['pad-gone'], $this->deletedPads);
		$this->assertSame([12, 13], $this->fileIds());
		$this->assertSame([['info', 'The file of a pad is gone for good; the pad is deleted.', 11]], $this->lines);
	}

	/**
	 * The pass keeps the marks in step with the file cache: a file under a
	 * user's or a team folder's trash gets one, a file back in Files loses
	 * it. A file gone without a mark keeps none, and its pad stays.
	 */
	public function testThePassKeepsTheMarksInStepWithTheFileCache(): void {
		$this->table(
			[
				self::row(1, 11, 'pad-user-trash'),
				self::row(2, 12, 'pad-team-trash'),
				self::row(3, 13, 'pad-restored', trashedAt: 100),
				self::row(4, 14, 'pad-in-files'),
				self::row(5, 15, 'pad-unmarked'),
			],
			[
				self::cached(11, 'files_trashbin/files/A.pad.d100'),
				self::cached(12, '__groupfolders/trash/3/B.pad.d100'),
				self::cached(13, 'files/C.pad'),
				self::cached(14, 'files/D.pad'),
			],
		);

		$this->sweep();
		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11 => FixedClock::NOW, 12 => FixedClock::NOW, 13 => null, 14 => null, 15 => null], $this->marks());
	}

	/**
	 * With `delete_on_trash` off no pad goes; the marks are kept all the
	 * same, so switching it on finds them in place.
	 */
	public function testWithDeletingOffNothingGoesButTheMarksAreKept(): void {
		$this->table([self::row(1, 11, 'pad-gone', trashedAt: 100), self::row(2, 12, 'pad-in-trash')], [self::cached(12, 'files_trashbin/files/B.pad.d100')]);
		$this->config['delete_on_trash'] = false;

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11 => 100, 12 => FixedClock::NOW], $this->marks());

		$this->config['delete_on_trash'] = true;
		$this->sweep();
		$this->assertSame(['pad-gone'], $this->deletedPads);
	}

	/**
	 * A pad Etherpad no longer has is gone as surely: its row goes. A pad
	 * Etherpad refuses to delete keeps its row for another try, with a
	 * warning, and the run goes on.
	 */
	public function testAPadAlreadyGoneCountsAndARefusalIsTriedAgain(): void {
		$this->table([
			self::row(1, 11, 'pad-already-gone', trashedAt: 100),
			self::row(2, 12, 'pad-refused', trashedAt: 101),
			self::row(3, 13, 'pad-fine', trashedAt: 102),
		], []);
		$this->padErrors = [
			'pad-already-gone' => new EtherpadRefusedException('padID does not exist'),
			'pad-refused' => new EtherpadRefusedException('apikey is invalid'),
		];

		$this->sweep();

		$this->assertSame(['pad-fine'], $this->deletedPads);
		$this->assertSame([12], $this->fileIds());
		$this->assertSame([
			['info', 'The file of a pad is gone for good; the pad is deleted.', 11],
			['warning', 'Could not delete the pad of a file gone for good; it is tried again.', 12],
			['info', 'The file of a pad is gone for good; the pad is deleted.', 13],
		], $this->lines);
	}

	/**
	 * Etherpad not answering ends the deletions of the run, with a line. The
	 * pass, which asks only the database, still takes its slice.
	 */
	public function testEtherpadNotAnsweringEndsTheDeletions(): void {
		$this->table([
			self::row(1, 11, 'pad-a', trashedAt: 100),
			self::row(2, 12, 'pad-b', trashedAt: 101),
			self::row(3, 13, 'pad-in-trash'),
		], [self::cached(13, 'files_trashbin/files/C.pad.d100')]);
		$this->padErrors = ['pad-a' => new EtherpadClientException('Etherpad API request failed: deletePad')];

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11, 12, 13], $this->fileIds());
		$this->assertSame(FixedClock::NOW, $this->marks()[13]);
		$this->assertSame([['info', 'Etherpad did not answer the sweep of files gone for good; it tries again next run.', null]], $this->lines);
	}

	/**
	 * A run spends its time budget and no more: out of it, the rest waits for
	 * the next run, quietly.
	 */
	public function testARunStopsAtItsTimeBudget(): void {
		$this->table([self::row(1, 11, 'pad-1', trashedAt: 100), self::row(2, 12, 'pad-2', trashedAt: 101)], []);
		$this->onDelete = function (): void {
			$this->clock->advance(19);
		};

		$this->sweep();

		$this->assertSame(['pad-1'], $this->deletedPads);
		$this->assertSame([12], $this->fileIds());
		$this->assertSame([['info', 'The file of a pad is gone for good; the pad is deleted.', 11]], $this->lines);
	}

	/**
	 * A file the file cache has again by the time its pad would go keeps it.
	 */
	public function testTheFileCacheIsAskedOnceMoreBeforeAPadGoes(): void {
		$this->table([self::row(1, 11, 'pad-1', trashedAt: 100)], []);
		$bindings = new class($this->db, $this->clock) extends BindingService {
			public function isFileGone(int $fileId): bool {
				return false;
			}
		};

		$this->sweep($bindings);

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11], $this->fileIds());
	}

	/**
	 * The pass takes 200 rows a run, from where the last one stopped, and
	 * starts over after a slice shorter than that. Rows that wait are the
	 * older sweep's and are not passed.
	 */
	public function testThePassGoesOnWhereItStoppedAndStartsOver(): void {
		$rows = [];
		for ($id = 1; $id <= 250; $id++) {
			$rows[] = self::row($id, 1000 + $id, 'pad-' . $id);
		}
		$rows[] = self::row(251, 2000, 'pad-waiting', state: BindingService::STATE_PENDING_DELETE);
		$cached = array_map(static fn (int $id): array => self::cached(1000 + $id, 'files/' . $id . '.pad'), range(1, 250));
		$cached[] = self::cached(2000, 'files_trashbin/files/waiting.pad.d100');
		$this->table($rows, $cached);

		$this->sweep();
		$this->assertSame(200, $this->config['cursor']);

		$this->sweep();
		$this->assertSame(0, $this->config['cursor']);
		$this->assertNull($this->marks()[2000]);
	}

	/**
	 * The request that deleted files past the trash deletes their pads: of
	 * the files it names, those gone from the file cache, active rows only.
	 * A file the trash took is passed by, and so is a file it does not name.
	 * With `delete_on_trash` off it deletes nothing.
	 */
	public function testTheRequestThatDeletedFilesPastTheTrashDeletesTheirPads(): void {
		$this->table([
			self::row(1, 11, 'pad-deleted', trashedAt: 100),
			self::row(2, 12, 'pad-trashed', trashedAt: 100),
			self::row(3, 13, 'pad-waiting', trashedAt: 100, state: BindingService::STATE_PENDING_DELETE),
			self::row(4, 14, 'pad-not-named', trashedAt: 100),
			self::row(5, 15, 'pad-unmarked'),
		], [self::cached(12, 'files_trashbin/files/B.pad.d100')]);

		$this->build()->discardDeleted([11, 12, 13, 15]);

		$this->assertSame(['pad-deleted', 'pad-unmarked'], $this->deletedPads);
		$this->assertSame([12, 13, 14], $this->fileIds());
		$this->assertSame([
			['info', 'The file of a pad is gone for good; the pad is deleted.', 11],
			['info', 'The file of a pad is gone for good; the pad is deleted.', 15],
		], $this->lines);

		$this->config['delete_on_trash'] = false;
		$this->build()->discardDeleted([14]);
		$this->assertSame([12, 13, 14], $this->fileIds());
	}

	/**
	 * The request spends a few seconds at most. What does not fit, and what
	 * finds Etherpad not answering, keeps its row and mark for the sweep,
	 * and nothing is thrown at the delete, which has succeeded.
	 */
	public function testTheRequestSpendsAFewSecondsAtMost(): void {
		$this->table([self::row(1, 11, 'pad-1', trashedAt: 100), self::row(2, 12, 'pad-2', trashedAt: 100)], []);
		$this->onDelete = function (): void {
			$this->clock->advance(4);
		};

		$this->build()->discardDeleted([11, 12]);

		$this->assertSame(['pad-1'], $this->deletedPads);
		$this->assertSame([12 => 100], $this->marks());

		$this->setUp();
		$this->table([self::row(1, 11, 'pad-1', trashedAt: 100)], []);
		$this->padErrors = ['pad-1' => new EtherpadClientException('Etherpad API request failed: deletePad')];

		$this->build()->discardDeleted([11]);

		$this->assertSame([11 => 100], $this->marks());
		$this->assertSame([['info', 'Could not delete the pads of files deleted past the trash; the sweep tries again.', null]], $this->lines);
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param list<array<string,mixed>> $fileCache
	 */
	private function table(array $rows, array $fileCache): void {
		$this->db = new InMemoryBindingTable($rows, $fileCache);
	}

	private function sweep(?BindingService $bindings = null): void {
		$this->build($bindings)->run();
	}

	private function build(?BindingService $bindings = null): GoneFileSweep {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('deletePad')->willReturnCallback(function (string $padId): void {
			if (isset($this->padErrors[$padId])) {
				throw $this->padErrors[$padId];
			}
			$this->deletedPads[] = $padId;
			if ($this->onDelete !== null) {
				($this->onDelete)();
			}
		});
		$config = $this->createMock(AppConfigService::class);
		$config->method('isDeleteOnTrashEnabled')->willReturnCallback(fn (): bool => (bool)$this->config['delete_on_trash']);
		$config->method('getGoneFileSweepCursor')->willReturnCallback(fn (): int => (int)$this->config['cursor']);
		$config->method('setGoneFileSweepCursor')->willReturnCallback(function (int $cursor): void {
			$this->config['cursor'] = $cursor;
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context) use ($level): void {
				$this->lines[] = [$level, $message, $context['fileId'] ?? null];
			});
		}
		return new GoneFileSweep(
			$bindings ?? new BindingService($this->db, $this->clock),
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$config,
			$this->clock,
			$logger,
		);
	}

	/** @return list<int> the files that still have a row */
	private function fileIds(): array {
		return array_map(static fn (array $row): int => $row['file_id'], $this->db->rows);
	}

	/** @return array<int,int|null> trashed_at by file */
	private function marks(): array {
		return array_column($this->db->rows, 'trashed_at', 'file_id');
	}

	/** @return array<string,mixed> */
	private static function row(int $id, int $fileId, string $padId, ?int $trashedAt = null, string $state = BindingService::STATE_ACTIVE): array {
		return ['id' => $id, 'file_id' => $fileId, 'pad_id' => $padId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => null, 'updated_at' => 100, 'trashed_at' => $trashedAt];
	}

	/** @return array<string,mixed> */
	private static function cached(int $fileId, string $path): array {
		return ['fileid' => $fileId, 'storage' => 1, 'path' => $path];
	}
}
