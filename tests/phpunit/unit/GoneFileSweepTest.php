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
 * as the database would answer: which pads go, which wait, and which rows
 * get or lose their dates.
 */
class GoneFileSweepTest extends TestCase {
	private const NOW = FixedClock::NOW;
	private const DAY = 24 * 60 * 60;

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
		$this->clock = new FixedClock(self::NOW);
		$this->config = ['delete_on_trash' => true, 'grace' => 7 * self::DAY, 'threshold' => 20, 'released_at' => 0, 'engaged' => false, 'cursor' => 0];
		$this->deletedPads = [];
		$this->padErrors = [];
		$this->lines = [];
	}

	/**
	 * A file marked as leaving Files and gone from the file cache is gone for
	 * good: its pad and row go, in one run, with a line naming the pad. One
	 * still in a trash, and one back in Files, keep theirs.
	 */
	public function testAFileSeenLeavingAndGoneTakesItsPad(): void {
		$this->table(
			[self::row(1, 11, 'pad-gone', trashedAt: 100), self::row(2, 12, 'pad-in-trash', trashedAt: 100), self::row(3, 13, 'pad-back', trashedAt: 100)],
			[self::cached(12, 'files_trashbin/files/Notes.pad.d100'), self::cached(13, 'files/Notes.pad')],
		);

		$this->sweep();

		$this->assertSame(['pad-gone'], $this->deletedPads);
		$this->assertSame([12, 13], $this->fileIds());
		$this->assertSame([['info', 'The file of a pad is gone for good; the pad is deleted.', 11]], $this->lines);
	}

	/**
	 * The pass over every row keeps the dates in step with the file cache:
	 * a file under a trash path gets the mark, one in Files loses it, a file
	 * missing without a mark starts its grace period, and one found again
	 * ends it.
	 */
	public function testThePassKeepsTheDatesInStepWithTheFileCache(): void {
		$this->table(
			[
				self::row(1, 11, 'pad-user-trash'),
				self::row(2, 12, 'pad-team-trash'),
				self::row(3, 13, 'pad-restored', trashedAt: 100),
				self::row(4, 14, 'pad-missing'),
				self::row(5, 15, 'pad-found-again', missingSince: 100),
			],
			[
				self::cached(11, 'files_trashbin/files/A.pad.d100'),
				self::cached(12, '__groupfolders/trash/3/B.pad.d100'),
				self::cached(13, 'files/C.pad'),
				self::cached(15, 'files/E.pad'),
			],
		);

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([
			11 => [self::NOW, null],
			12 => [self::NOW, null],
			13 => [null, null],
			14 => [null, self::NOW],
			15 => [null, null],
		], $this->dates());
	}

	/**
	 * A file missing without a mark keeps its pad through the grace period
	 * and loses it after, with a warning naming the pad. As many such files
	 * as the threshold do not hold the brake.
	 */
	public function testAFileMissingWithoutAMarkTakesItsPadAfterTheGracePeriod(): void {
		$this->table([
			self::row(1, 11, 'pad-within', missingSince: self::NOW - 7 * self::DAY + 1),
			self::row(2, 12, 'pad-past', missingSince: self::NOW - 7 * self::DAY),
		], []);
		$this->config['threshold'] = 2;

		$this->sweep();

		$this->assertSame(['pad-past'], $this->deletedPads);
		$this->assertSame([11], $this->fileIds());
		$this->assertSame([['warning', 'The file of a pad has been missing past the grace period without passing a trash; the pad is deleted.', 12]], $this->lines);
	}

	/**
	 * More files missing without a mark since the last release than the
	 * threshold: the brake holds as they go missing, warned of once, and
	 * keeps every pad past its grace period - also one in the same slice,
	 * ahead of them - until an admin releases it. Files seen leaving are
	 * not held.
	 */
	public function testTheBrakeHoldsGraceDeletionsUntilReleased(): void {
		$this->table([
			self::row(1, 11, 'pad-old', missingSince: self::NOW - 8 * self::DAY),
			self::row(2, 12, 'pad-trashed', trashedAt: 100),
			self::row(3, 13, 'pad-3'),
			self::row(4, 14, 'pad-4'),
			self::row(5, 15, 'pad-5'),
		], []);
		$this->config['threshold'] = 3;
		$brake = ['warning', 'Many .pad files went missing at once without passing a trash; their pads are kept until an admin releases the brake.', null];

		$this->sweep();
		$this->assertSame(['pad-trashed'], $this->deletedPads, 'held as they go missing');
		$this->assertTrue($this->config['engaged']);
		$this->assertContains($brake, $this->lines);

		$this->lines = [];
		$this->clock->advance(8 * self::DAY);
		$this->sweep();
		$this->assertSame(['pad-trashed'], $this->deletedPads, 'held past their grace period');
		$this->assertSame([], $this->lines, 'warned once');

		$this->sweep(release: true);
		$this->assertSame(['pad-trashed', 'pad-old', 'pad-3', 'pad-4', 'pad-5'], $this->deletedPads, 'released');
		$this->assertSame([$this->clock->getTime(), false], [$this->config['released_at'], $this->config['engaged']]);
	}

	/**
	 * The brake follows the files: found again, they no longer hold it.
	 */
	public function testTheBrakeLetsGoWhenTheFilesAreFoundAgain(): void {
		$this->table([
			self::row(1, 11, 'pad-1', missingSince: self::NOW - 100),
			self::row(2, 12, 'pad-2', missingSince: self::NOW - 100),
		], [self::cached(11, 'files/1.pad'), self::cached(12, 'files/2.pad')]);
		$this->config['threshold'] = 1;
		$this->config['engaged'] = true;

		$this->sweep();

		$this->assertFalse($this->config['engaged']);
		$this->assertSame([], $this->lines);
	}

	/**
	 * With `delete_on_trash` off no pad goes, neither gone for good nor past
	 * its grace period; the dates and the brake are kept all the same, so
	 * switching it on finds them in place.
	 */
	public function testWithDeletingOffNothingGoesButTheDatesAreKept(): void {
		$this->table([
			self::row(1, 11, 'pad-gone', trashedAt: 100),
			self::row(2, 12, 'pad-past', missingSince: self::NOW - 8 * self::DAY),
			self::row(3, 13, 'pad-missing'),
		], []);
		$this->config['delete_on_trash'] = false;

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11 => [100, null], 12 => [null, self::NOW - 8 * self::DAY], 13 => [null, self::NOW]], $this->dates());

		$this->config['threshold'] = 1;
		$this->sweep();
		$this->assertTrue($this->config['engaged']);
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
	 * Etherpad not answering ends the run, with a line. Dates already set
	 * stay; the slice it cut short is taken again.
	 */
	public function testEtherpadNotAnsweringEndsTheRun(): void {
		$this->table([
			self::row(6, 16, 'pad-past', missingSince: self::NOW - 8 * self::DAY),
			self::row(7, 17, 'pad-after', missingSince: self::NOW - 8 * self::DAY),
			self::row(8, 18, 'pad-missing'),
		], []);
		$this->config['cursor'] = 5;
		$this->padErrors = ['pad-past' => new EtherpadClientException('Etherpad API request failed: deletePad')];

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([16, 17, 18], $this->fileIds());
		$this->assertSame(5, $this->config['cursor']);
		$this->assertSame(self::NOW, $this->dates()[18][1]);
		$this->assertSame([['info', 'Etherpad did not answer the sweep of files gone for good; it tries again next run.', null]], $this->lines);
	}

	/**
	 * A run spends its time budget and no more: out of it, the rest waits for
	 * the next run, quietly.
	 */
	public function testARunStopsAtItsTimeBudget(): void {
		$this->table([
			self::row(1, 11, 'pad-1', trashedAt: 100),
			self::row(2, 12, 'pad-2', trashedAt: 101),
			self::row(3, 13, 'pad-3', missingSince: self::NOW - 8 * self::DAY),
		], []);
		$this->onDelete = function (): void {
			$this->clock->advance(19);
		};

		$this->sweep();

		$this->assertSame(['pad-1'], $this->deletedPads);
		$this->assertSame([12, 13], $this->fileIds());
		$this->assertSame(0, $this->config['cursor']);
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
		$this->table($rows, $cached);

		$this->sweep();
		$this->assertSame(200, $this->config['cursor']);

		$this->sweep();
		$this->assertSame(0, $this->config['cursor']);
		$this->assertSame([null, null], $this->dates()[2000]);
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param list<array<string,mixed>> $fileCache
	 */
	private function table(array $rows, array $fileCache): void {
		$this->db = new InMemoryBindingTable($rows, $fileCache);
	}

	/** A run, or with $release an admin's release of the brake first. */
	private function sweep(?BindingService $bindings = null, bool $release = false): void {
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
		$config->method('getGoneFileGraceSeconds')->willReturnCallback(fn (): int => (int)$this->config['grace']);
		$config->method('getGoneFileBrakeThreshold')->willReturnCallback(fn (): int => (int)$this->config['threshold']);
		$config->method('getGoneFileBrakeReleasedAt')->willReturnCallback(fn (): int => (int)$this->config['released_at']);
		$config->method('isGoneFileBrakeEngaged')->willReturnCallback(fn (): bool => (bool)$this->config['engaged']);
		$config->method('setGoneFileBrakeEngaged')->willReturnCallback(function (bool $engaged): void {
			$this->config['engaged'] = $engaged;
		});
		$config->method('releaseGoneFileBrake')->willReturnCallback(function (int $now): void {
			$this->config['released_at'] = $now;
			$this->config['engaged'] = false;
		});
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
		$sweep = new GoneFileSweep(
			$bindings ?? new BindingService($this->db, $this->clock),
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$config,
			$this->clock,
			$logger,
		);
		if ($release) {
			$sweep->releaseBrake();
		}
		$sweep->run();
	}

	/** @return list<int> the files that still have a row */
	private function fileIds(): array {
		return array_map(static fn (array $row): int => $row['file_id'], $this->db->rows);
	}

	/** @return array<int,array{int|null,int|null}> trashed_at and missing_since by file */
	private function dates(): array {
		$dates = [];
		foreach ($this->db->rows as $row) {
			$dates[$row['file_id']] = [$row['trashed_at'], $row['missing_since']];
		}
		return $dates;
	}

	/** @return array<string,mixed> */
	private static function row(int $id, int $fileId, string $padId, ?int $trashedAt = null, ?int $missingSince = null, string $state = BindingService::STATE_ACTIVE): array {
		return ['id' => $id, 'file_id' => $fileId, 'pad_id' => $padId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => null, 'updated_at' => 100, 'trashed_at' => $trashedAt, 'missing_since' => $missingSince];
	}

	/** @return array<string,mixed> */
	private static function cached(int $fileId, string $path): array {
		return ['fileid' => $fileId, 'storage' => 1, 'path' => $path];
	}
}
