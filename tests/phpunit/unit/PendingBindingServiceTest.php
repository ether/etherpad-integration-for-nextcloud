<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PendingBindingService;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PendingBindingServiceTest extends TestCase {
	/**
	 * Where a waiting row's file is now decides what happens to it: in
	 * Files it is settled as a restore would, in the trash it waits, and
	 * gone for good its deletion is carried out. Only that last one can
	 * delete a pad.
	 */
	public function testEachRowGoesByWhereItsFileIsNow(): void {
		$bindings = $this->bindings(
			restores: [['file_id' => 1, 'pad_id' => 'pad-1', 'state' => BindingService::STATE_RESTORE_PENDING]],
			deletes: [
				['file_id' => 2, 'pad_id' => 'pad-2', 'state' => BindingService::STATE_PENDING_DELETE, 'file_path' => 'files_trashbin/files/Two.pad.d100'],
				['file_id' => 3, 'pad_id' => 'pad-3', 'state' => BindingService::STATE_PENDING_DELETE, 'file_path' => null],
				['file_id' => 4, 'pad_id' => 'pad-4', 'state' => BindingService::STATE_PENDING_DELETE, 'file_path' => 'files/Four.pad'],
			],
		);
		$files = [1 => $this->file(1, '/alice/files/One.pad'), 4 => $this->file(4, '/alice/files/Four.pad')];

		$lifecycle = $this->createMock(LifecycleService::class);
		$settled = [];
		$lifecycle->method('settleWaitingFile')->willReturnCallback(static function (File $file) use (&$settled): array {
			$settled[] = $file->getId();
			return ['status' => LifecycleService::RESULT_RESTORED, 'file_id' => $file->getId()];
		});
		$lifecycle->expects($this->once())->method('finishOwedDeletion')->with(3, 'pad-3', 15)->willReturn('deleted');

		$result = $this->service($bindings, $lifecycle, $this->root($files))->settleByAge(0, 3600, 50);

		$this->assertSame([1, 4], $settled);
		$this->assertSame(['checked' => 3, 'settled' => 3], $result);
	}

	/**
	 * A file that cannot be found by its id, or turns out to sit in a trash,
	 * is left for a later run rather than guessed at.
	 */
	public function testAFileThatCannotBeFoundInFilesIsLeftForLater(): void {
		$bindings = $this->bindings(restores: [
			['file_id' => 1, 'pad_id' => 'pad-1', 'state' => BindingService::STATE_RESTORE_PENDING],
			['file_id' => 2, 'pad_id' => 'pad-2', 'state' => BindingService::STATE_RESTORE_PENDING],
		]);
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->expects($this->never())->method('settleWaitingFile');

		$result = $this->service($bindings, $lifecycle, $this->root([2 => $this->file(2, '/alice/files_trashbin/files/Two.pad.d100')]))
			->settleByAge(0, null, 50);

		$this->assertSame(['checked' => 0, 'settled' => 0], $result);
	}

	/**
	 * Rows stay until they are settled, so an Etherpad that is down would
	 * otherwise cost every run a full timeout per row. A few without an
	 * answer are read as an outage, and the run ends there.
	 */
	public function testARunEndsAfterAFewRowsWithoutAnAnswer(): void {
		$rows = [];
		$files = [];
		for ($id = 1; $id <= 8; $id++) {
			$rows[] = ['file_id' => $id, 'pad_id' => 'pad-' . $id, 'state' => BindingService::STATE_RESTORE_PENDING];
			$files[$id] = $this->file($id, '/alice/files/' . $id . '.pad');
		}
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->expects($this->exactly(5))
			->method('settleWaitingFile')
			->willReturn(['status' => LifecycleService::RESULT_SKIPPED, 'reason' => 'pad_presence_unknown', 'file_id' => 0]);

		$result = $this->service($this->bindings(restores: $rows), $lifecycle, $this->root($files))->settleByAge(0, null, 50);

		$this->assertSame(['checked' => 5, 'settled' => 0], $result);
	}

	/**
	 * Each probe gets what is left of the run's time, and a row that could
	 * not finish inside it is not started.
	 */
	public function testEachProbeIsCutToWhatIsLeftOfTheRun(): void {
		$clock = new FixedClock();
		$rows = [];
		$files = [];
		for ($id = 1; $id <= 3; $id++) {
			$rows[] = ['file_id' => $id, 'pad_id' => 'pad-' . $id, 'state' => BindingService::STATE_RESTORE_PENDING];
			$files[$id] = $this->file($id, '/alice/files/' . $id . '.pad');
		}
		$timeouts = [];
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->method('settleWaitingFile')->willReturnCallback(static function (File $file, ?int $timeout) use ($clock, &$timeouts): array {
			$timeouts[] = $timeout;
			$clock->advance(10);
			return ['status' => LifecycleService::RESULT_RESTORED, 'file_id' => $file->getId()];
		});

		$this->service($this->bindings(restores: $rows), $lifecycle, $this->root($files), $clock)->settleByAge(0, null, 50);

		$this->assertSame([15, 10], $timeouts);
	}

	/** A row that throws is logged and counted, and the rows behind it still get their turn. */
	public function testARowThatThrowsDoesNotStopTheRest(): void {
		$bindings = $this->bindings(restores: [
			['file_id' => 1, 'pad_id' => 'pad-1', 'state' => BindingService::STATE_RESTORE_PENDING],
			['file_id' => 2, 'pad_id' => 'pad-2', 'state' => BindingService::STATE_RESTORE_PENDING],
		]);
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->method('settleWaitingFile')->willReturnCallback(static function (File $file): array {
			if ($file->getId() === 1) {
				throw new \RuntimeException('database went away');
			}
			return ['status' => LifecycleService::RESULT_RESTORED, 'file_id' => 2];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not settle a pad binding that waits.', $this->anything());

		$result = $this->service($bindings, $lifecycle, $this->root([1 => $this->file(1, '/a/files/1.pad'), 2 => $this->file(2, '/a/files/2.pad')]), logger: $logger)
			->settleByAge(0, null, 50);

		$this->assertSame(['checked' => 2, 'settled' => 1], $result);
	}

	/** The admin page shows what a run did and what is left of either kind. */
	public function testSettleReportsWhatIsLeft(): void {
		$bindings = $this->bindings();
		$bindings->method('countByState')->willReturnMap([
			[BindingService::STATE_RESTORE_PENDING, 1],
			[BindingService::STATE_PENDING_DELETE, 4],
		]);

		$result = $this->service($bindings, $this->createMock(LifecycleService::class), $this->root([]))->settle(50);

		$this->assertSame(['checked' => 0, 'settled' => 0, 'pending_restores' => 1, 'pending_deletes' => 4], $result);
	}

	/**
	 * @param list<array<string,mixed>> $restores
	 * @param list<array<string,mixed>> $deletes
	 */
	private function bindings(array $restores = [], array $deletes = []): BindingService&MockObject {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('findRestorePendingByAge')->willReturn($restores);
		$bindings->method('findPendingDeleteByAge')->willReturn($deletes);
		return $bindings;
	}

	private function file(int $id, string $path): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($path);
		return $file;
	}

	/** @param array<int,File> $files */
	private function root(array $files): IRootFolder {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => $files[$id] ?? null);
		return $root;
	}

	private function service(BindingService $bindings, LifecycleService $lifecycle, IRootFolder $root, ?FixedClock $clock = null, ?LoggerInterface $logger = null): PendingBindingService {
		return new PendingBindingService($bindings, $lifecycle, $root, $logger ?? $this->createMock(LoggerInterface::class), $clock ?? new FixedClock());
	}
}
