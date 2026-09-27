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
		$this->config = ['delete_on_trash' => true];
		$this->deletedPads = [];
		$this->padErrors = [];
		$this->lines = [];
	}

	/**
	 * A file seen deleted for good and gone from the file cache takes its
	 * pad and row along, in one run, with a line naming the pad. A marked
	 * file the file cache still has keeps its pad - a home's, marked just
	 * before Nextcloud clears it - and so does one gone without a mark.
	 */
	public function testAFileSeenDeletedForGoodTakesItsPad(): void {
		$this->table(
			[self::row(1, 11, 'pad-gone', goneAfter: 100), self::row(2, 12, 'pad-still-there', goneAfter: FixedClock::NOW), self::row(3, 13, 'pad-unmarked')],
			[self::cached(12, 'files/Notes.pad')],
		);

		$this->sweep();

		$this->assertSame(['pad-gone'], $this->deletedPads);
		$this->assertSame([12, 13], $this->fileIds());
		$this->assertSame([['info', 'The file of a pad is gone for good; the pad is deleted.', 11]], $this->lines);
	}

	/**
	 * With `delete_on_trash` off no pad goes; the marks are kept all the
	 * same, so switching it on finds them in place.
	 */
	public function testWithDeletingOffNothingGoesButTheMarksAreKept(): void {
		$this->table([self::row(1, 11, 'pad-gone', goneAfter: 100)], []);
		$this->config['delete_on_trash'] = false;

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11 => 100], $this->marks());

		$this->config['delete_on_trash'] = true;
		$this->sweep();
		$this->assertSame(['pad-gone'], $this->deletedPads);
	}

	/**
	 * A pad Etherpad no longer has is gone as surely: its row goes. A pad
	 * Etherpad refuses to delete keeps its row for another try an hour
	 * later, with a warning, and the run goes on.
	 */
	public function testAPadAlreadyGoneCountsAndARefusalIsTriedAgain(): void {
		$this->table([
			self::row(1, 11, 'pad-already-gone', goneAfter: 100),
			self::row(2, 12, 'pad-refused', goneAfter: 101),
			self::row(3, 13, 'pad-fine', goneAfter: 102),
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
		$this->assertSame([['warning', 'Could not delete the pad of a file gone for good; it is tried again in an hour.', 12]], $this->lines);
	}

	/** Etherpad not answering ends the run, with a line; the marks wait for the next. */
	public function testEtherpadNotAnsweringEndsTheRun(): void {
		$this->table([self::row(1, 11, 'pad-a', goneAfter: 100), self::row(2, 12, 'pad-b', goneAfter: 101)], []);
		$this->padErrors = ['pad-a' => new EtherpadClientException('Etherpad API request failed: deletePad')];

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11 => 100, 12 => 101], $this->marks());
		$this->assertSame([['info', 'Etherpad did not answer the sweep of files gone for good; it tries again next run.', null]], $this->lines);
	}

	/**
	 * A run spends its time budget and no more: out of it, the rest waits for
	 * the next run, quietly.
	 */
	public function testARunStopsAtItsTimeBudget(): void {
		$this->table([self::row(1, 11, 'pad-1', goneAfter: 100), self::row(2, 12, 'pad-2', goneAfter: 101)], []);
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
		$this->table([self::row(1, 11, 'pad-1', goneAfter: 100)], []);
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
	 * A mark is due once its grace is over: a run of the job passes a fresh
	 * one by, an admin's settle takes it at once. A mark postponed past the
	 * grace waits for both.
	 */
	public function testAFreshMarkWaitsForTheJobButNotForAnAdmin(): void {
		$this->table([
			self::row(1, 11, 'pad-fresh', goneAfter: FixedClock::NOW + BindingService::GONE_GRACE_SECONDS),
			self::row(2, 12, 'pad-postponed', goneAfter: FixedClock::NOW + BindingService::GONE_GRACE_SECONDS + 1),
		], []);

		$this->sweep();
		$this->assertSame([], $this->deletedPads);

		$this->build()->run(atOnce: true);
		$this->assertSame(['pad-fresh'], $this->deletedPads);
	}

	/**
	 * A mark whose file the file cache still has an hour after it was due
	 * did not happen - a deletion rolled back, an account whose files were
	 * left - and is cleared, with deleting off too. Sooner, it stays.
	 */
	public function testAMarkThatDidNotHappenIsCleared(): void {
		$this->table([
			self::row(1, 11, 'pad-left', goneAfter: FixedClock::NOW - 3600),
			self::row(2, 12, 'pad-recent', goneAfter: FixedClock::NOW - 3599),
		], [self::cached(11, 'files/Left.pad'), self::cached(12, 'files/Recent.pad')]);
		$this->config['delete_on_trash'] = false;

		$this->sweep();

		$this->assertSame([], $this->deletedPads);
		$this->assertSame([11 => null, 12 => FixedClock::NOW - 3599], $this->marks());
		$this->assertSame([['info', 'Cleared the marks of files still there an hour after their deletion was due; it did not happen.', null]], $this->lines);
	}

	/**
	 * A run takes batch after batch while its budget lasts, ten at most: a
	 * folder of thousands of pads deleted for good goes within a few runs.
	 */
	public function testARunTakesTenBatchesAtMost(): void {
		$this->table(array_map(static fn (int $i): array => self::row($i, 1000 + $i, 'pad-' . $i, goneAfter: 100), range(1, 2001)), []);

		$this->sweep();

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

	/** @return array<int,int|null> gone_after by file */
	private function marks(): array {
		return array_column($this->db->rows, 'gone_after', 'file_id');
	}

	/** @return array<string,mixed> */
	private static function row(int $id, int $fileId, string $padId, ?int $goneAfter = null, string $state = BindingService::STATE_ACTIVE): array {
		return ['id' => $id, 'file_id' => $fileId, 'pad_id' => $padId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => null, 'updated_at' => 100, 'gone_after' => $goneAfter];
	}

	/** @return array<string,mixed> */
	private static function cached(int $fileId, string $path): array {
		return ['fileid' => $fileId, 'storage' => 1, 'path' => $path];
	}
}
