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
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The sweep of files gone for good, over the binding table and file cache
 * as the database would answer: which pads go, and which stay.
 */
class GoneFileSweepTest extends TestCase {
	private InMemoryBindingTable $db;
	private FixedClock $clock;
	/** @var array<string,bool> the app config the sweep reads */
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
		$this->config = ['delete_pad_with_file' => true];
		$this->deletedPads = [];
		$this->padErrors = [];
		$this->lines = [];
	}

	/**
	 * A file seen deleted for good and gone from the file cache takes its
	 * pad and row along, in one run, with a line naming the pad. One seen
	 * deleted that the file cache still has keeps its pad - a home's, just
	 * before Nextcloud clears it - and so does one gone without being seen.
	 */
	public function testAFileSeenDeletedForGoodTakesItsPad(): void {
		$this->table(
			[self::row(1, 11, 'pad-gone', seenAt: 100), self::row(2, 12, 'pad-still-there', seenAt: FixedClock::NOW - 3000), self::row(3, 13, 'pad-unseen')],
			[self::cached(12, 'files/Notes.pad')],
		);

		$this->assertSame(['checked' => 1, 'deleted' => 1], $this->sweep());

		$this->assertSame(['pad-gone'], $this->deletedPads);
		$this->assertSame([12 => BindingService::STATE_PENDING_DELETE, 13 => BindingService::STATE_ACTIVE], $this->states());
		$this->assertSame([['info', 'The file of a pad is gone for good; the pad is deleted.', 11]], $this->lines);
	}

	/**
	 * With deleting off no pad goes; the rows keep waiting all the same, so
	 * switching it on finds them in place.
	 */
	public function testWithDeletingOffNothingGoesButTheRowsWait(): void {
		$this->table([self::row(1, 11, 'pad-gone', seenAt: 100)], []);
		$this->config['delete_pad_with_file'] = false;

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11 => BindingService::STATE_PENDING_DELETE], $this->states());

		$this->config['delete_pad_with_file'] = true;
		$this->sweep();
		$this->assertSame(['pad-gone'], $this->deletedPads);
	}

	/**
	 * A pad Etherpad no longer has is gone as surely: its row goes. A pad
	 * Etherpad refuses to delete keeps its row for another try an hour
	 * later, with a warning the first time, and the run goes on.
	 */
	public function testAPadAlreadyGoneCountsAndARefusalIsTriedAgain(): void {
		$this->table([
			self::row(1, 11, 'pad-already-gone', seenAt: 100),
			self::row(2, 12, 'pad-refused', seenAt: 101),
			self::row(3, 13, 'pad-fine', seenAt: 102),
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
			['warning', 'Could not delete the pad of a file gone for good; it is tried again in an hour.', 12],
			['info', 'The file of a pad is gone for good; the pad is deleted.', 13],
		], $this->lines);

		// Not before the hour is up: a refusal neither holds the queue's
		// head nor warns every run.
		$this->lines = [];
		$this->clock->advance(3599);
		$this->sweep();
		$this->assertSame([], $this->lines);
		$this->clock->advance(1);
		$this->sweep();
		$this->assertSame([['info', 'Could not delete the pad of a file gone for good; it is tried again in an hour.', 12]], $this->lines);
	}

	/** Etherpad not answering ends the run, with a line; the rows wait for the next. */
	public function testEtherpadNotAnsweringEndsTheRun(): void {
		$this->table([self::row(1, 11, 'pad-a', seenAt: 100), self::row(2, 12, 'pad-b', seenAt: 101)], []);
		$this->padErrors = ['pad-a' => new EtherpadClientException('Etherpad API request failed: deletePad')];

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11, 12], $this->fileIds());
		$this->assertSame([['info', 'Etherpad did not answer the sweep of files gone for good; it tries again next run.', null]], $this->lines);
	}

	/**
	 * A run spends its time budget and no more: out of it, the rest waits for
	 * the next run, quietly.
	 */
	public function testARunStopsAtItsTimeBudget(): void {
		$this->table([self::row(1, 11, 'pad-1', seenAt: 100), self::row(2, 12, 'pad-2', seenAt: 101)], []);
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
		$this->table([self::row(1, 11, 'pad-1', seenAt: 100)], []);
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
	 * A file seen deleted waits out its grace for the job, but not for an
	 * admin's settle. One whose pad was refused waits for its hour for both.
	 */
	public function testAFreshRowWaitsForTheJobButNotForAnAdmin(): void {
		$this->table([
			self::row(1, 11, 'pad-fresh', seenAt: FixedClock::NOW - BindingService::GONE_GRACE_SECONDS + 1),
			self::row(2, 12, 'pad-refused', seenAt: 100, triedAt: FixedClock::NOW - 10),
		], []);

		$this->sweep();
		$this->assertSame([], $this->deletedPads);

		$this->build()->run(atOnce: true);
		$this->assertSame(['pad-fresh'], $this->deletedPads);
	}

	/**
	 * A file seen deleted that the file cache still has an hour later was
	 * not deleted after all - a deletion rolled back, an account whose
	 * files were left - and its row is active again, with deleting off too.
	 * Sooner, it waits.
	 */
	public function testADeletionThatDidNotHappenLeavesTheRowActive(): void {
		$this->table([
			self::row(1, 11, 'pad-left', seenAt: FixedClock::NOW - 3600),
			self::row(2, 12, 'pad-recent', seenAt: FixedClock::NOW - 3599),
		], [self::cached(11, 'files/Left.pad'), self::cached(12, 'files/Recent.pad')]);
		$this->config['delete_pad_with_file'] = false;

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11 => BindingService::STATE_ACTIVE, 12 => BindingService::STATE_PENDING_DELETE], $this->states());
		$this->assertSame([['info', 'Files seen deleted for good are still there an hour later; their deletion did not happen, and they keep their pads.', null]], $this->lines);
	}

	/**
	 * A run takes batch after batch while its budget lasts, ten at most: a
	 * folder of thousands of pads deleted for good goes within a few runs.
	 */
	public function testARunTakesTenBatchesAtMost(): void {
		$this->table(array_map(static fn (int $i): array => self::row($i, 1000 + $i, 'pad-' . $i, seenAt: 100), range(1, 2001)), []);

		$this->assertSame(['checked' => 2000, 'deleted' => 2000], $this->sweep());

		$this->assertCount(2000, $this->deletedPads);
		$this->assertSame([3001], $this->fileIds());
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param list<array<string,mixed>> $fileCache
	 */
	private function table(array $rows, array $fileCache): void {
		$this->db = new InMemoryBindingTable($rows, $fileCache);
	}

	/** @return array{checked: int, deleted: int} */
	private function sweep(?BindingService $bindings = null): array {
		return $this->build($bindings)->run();
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
		$config->method('isDeletePadWithFileEnabled')->willReturnCallback(fn (): bool => (bool)$this->config['delete_pad_with_file']);
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

	/** @return array<int,string> state by file */
	private function states(): array {
		return array_column($this->db->rows, 'state', 'file_id');
	}

	/**
	 * A row: active, or - $seenAt given - one whose file was seen deleted
	 * for good then, last tried at $triedAt.
	 *
	 * @return array<string,mixed>
	 */
	private static function row(int $id, int $fileId, string $padId, ?int $seenAt = null, ?int $triedAt = null): array {
		$state = $seenAt === null ? BindingService::STATE_ACTIVE : BindingService::STATE_PENDING_DELETE;
		return ['id' => $id, 'file_id' => $fileId, 'pad_id' => $padId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => $seenAt, 'updated_at' => $triedAt ?? $seenAt ?? 100];
	}

	/** @return array<string,mixed> */
	private static function cached(int $fileId, string $path): array {
		return ['fileid' => $fileId, 'storage' => 1, 'path' => $path];
	}
}
