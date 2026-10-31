<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeFolderSessionsJob;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\FolderSessionRevoker;
use OCA\EtherpadNextcloud\Service\GroupSessionRevoker;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use OCA\EtherpadNextcloud\Tests\Support\TestClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The groups of what a delete's walk did not reach: only those of active
 * protected rows under the folder as the file cache has it now, each once,
 * and each to its own job. The job's rules on who keeps sessions are
 * GroupSessionRevokerTest's.
 */
class FolderSessionRevokerTest extends TestCase {
	private const STORAGE = 1;
	private const FOLDER = 10;
	private const TRASHED = 'files_trashbin/files/big.d1';

	public function testQueuesTheGroupsOfTheRowsUnderTheFolder(): void {
		$table = new InMemoryBindingTable([
			$this->row(11, 'one'),
			$this->row(12, 'one$other'),
			$this->row(13, 'two'),
			// Beside the folder, under a name that begins as its does.
			$this->row(21, 'beside'),
			// Same path, another storage.
			$this->row(22, 'elsewhere'),
			// In Files, restored out of the folder on its own.
			$this->row(23, 'restored'),
			// No file the file cache has.
			$this->row(24, 'vanished'),
			$this->row(25, 'waiting', BindingService::ACCESS_PROTECTED, BindingService::STATE_PENDING_DELETE),
			$this->row(26, 'public', BindingService::ACCESS_PUBLIC),
			// A row can name a pad of no group.
			['file_id' => 27, 'pad_id' => 'no-group', 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_ACTIVE],
		], [
			$this->cached(self::FOLDER, self::TRASHED),
			$this->cached(11, self::TRASHED . '/11.pad'),
			$this->cached(12, self::TRASHED . '/deep/down/12.pad'),
			$this->cached(13, self::TRASHED . '/13.pad'),
			$this->cached(21, self::TRASHED . '2/21.pad'),
			$this->cached(22, self::TRASHED . '/22.pad', 2),
			$this->cached(23, 'files/23.pad'),
			$this->cached(25, self::TRASHED . '/25.pad'),
			$this->cached(26, self::TRASHED . '/26.pad'),
			$this->cached(27, self::TRASHED . '/27.pad'),
		]);
		$queued = [];

		$pass = $this->revoker($table, $queued)->pass(self::FOLDER, 0);

		$this->assertSame(['g.one', 'g.two'], $queued);
		$this->assertTrue($pass['ended']);
		$this->assertFalse($pass['failed']);
	}

	/** A run begins past the rows dealt with, and pages through the rest. */
	public function testGoesOnPastTheRowsDealtWith(): void {
		[$rows, $cached] = $this->folderOfRows(1000, 1200);
		$queued = [];

		$pass = $this->revoker(new InMemoryBindingTable($rows, $cached), $queued)->pass(self::FOLDER, 2100);

		$this->assertCount(99, $queued);
		$this->assertSame('g.g2101', $queued[0]);
		$this->assertTrue($pass['ended']);
		$this->assertSame(2199, $pass['after']);
	}

	/** Through every page, full ones too, each group once. */
	public function testGoesThroughSeveralFullPages(): void {
		[$rows, $cached] = $this->folderOfRows(1000, 1200, 200);
		$queued = [];

		$pass = $this->revoker(new InMemoryBindingTable($rows, $cached), $queued)->pass(self::FOLDER, 0);

		$this->assertCount(200, $queued);
		$this->assertSame(['after' => 2199, 'ended' => true, 'failed' => false], $pass);
	}

	/** Each group is a job: a pass queues so many, and the next goes on from the row it left. */
	public function testQueuesAtMostSoManyGroupsAPass(): void {
		[$rows, $cached] = $this->folderOfRows(1000, 300);
		$table = new InMemoryBindingTable($rows, $cached);
		$queued = [];

		$first = $this->revoker($table, $queued)->pass(self::FOLDER, 0);

		$this->assertCount(250, $queued);
		$this->assertSame(['after' => 1249, 'ended' => false, 'failed' => false], $first);

		$queued = [];
		$second = $this->revoker($table, $queued)->pass(self::FOLDER, $first['after']);

		$this->assertCount(50, $queued);
		$this->assertSame('g.g1250', $queued[0]);
		$this->assertSame(['after' => 1299, 'ended' => true, 'failed' => false], $second);
	}

	/** Exactly as many groups as a pass may queue end it, with no further pass. */
	public function testAPassEndsAtExactlyItsLimitOfGroups(): void {
		[$rows, $cached] = $this->folderOfRows(1000, 250);
		$queued = [];

		$pass = $this->revoker(new InMemoryBindingTable($rows, $cached), $queued)->pass(self::FOLDER, 0);

		$this->assertCount(250, $queued);
		$this->assertTrue($pass['ended']);
	}

	/**
	 * Expiry before the third queueing preserves the last processed row, so
	 * the next pass can retry the group not queued.
	 */
	public function testStopsInAPageWhenTheBudgetIsSpent(): void {
		[$rows, $cached] = $this->folderOfRows(1000, 3);
		// A row beside the folder after the one left: the cursor stays before that one.
		$rows[] = $this->row(1003, 'beside');
		$cached[] = $this->cached(1003, 'files/1003.pad');
		$queued = [];
		$clock = new class extends TestClock {
			public int $at = 1000;

			public function now(): \DateTimeImmutable {
				return new \DateTimeImmutable('@' . $this->at);
			}
		};
		// Time expires after two groups have been queued.
		$onQueue = static function () use (&$queued, $clock): void {
			if (count($queued) === 2) {
				$clock->at += 1000;
			}
		};

		$pass = $this->revoker(new InMemoryBindingTable($rows, $cached), $queued, clock: $clock, onQueue: $onQueue)->pass(self::FOLDER, 0);

		$this->assertSame(['g.g1000', 'g.g1001'], $queued);
		$this->assertSame(['after' => 1001, 'ended' => false, 'failed' => false], $pass);
	}

	/** A later run takes up the rows a spent budget left. */
	public function testTheNextRunTakesWhatABudgetSpentLeft(): void {
		[$rows, $cached] = $this->folderOfRows(1000, 3);
		$queued = [];

		$pass = $this->revoker(new InMemoryBindingTable($rows, $cached), $queued)->pass(self::FOLDER, 1001);

		$this->assertSame(['g.g1002'], $queued);
		$this->assertTrue($pass['ended']);
	}

	/** A look-up that fails after some rows keeps how far the run got, and says so. */
	public function testALookUpThatFailsKeepsTheProgressMade(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('placeOf')->willReturn([self::STORAGE, self::TRASHED]);
		$bindings->method('placesOf')->willReturn([11 => [self::STORAGE, self::TRASHED . '/11.pad']]);
		$bindings->method('anyInFiles')->willReturn(false);
		$bindings->method('activeProtectedPadsAfter')->willReturnCallback(static function (int $after): array {
			if ($after > 0) {
				throw new \RuntimeException('database went away');
			}
			return [11 => 'g.one$p-11'];
		});
		$groups = $this->createMock(GroupSessionRevoker::class);
		$groups->expects($this->once())->method('queue')->with('g.one')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not look through the rows for a deleted folder\'s protected pads.', $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === self::FOLDER && isset($context['error'])));
		$clock = new FixedClock();

		$pass = (new FolderSessionRevoker($bindings, $groups, $this->createMock(IJobList::class), $logger, $clock))->pass(self::FOLDER, 0);

		$this->assertSame(['after' => 11, 'ended' => false, 'failed' => true], $pass);
	}

	/** A look at the folder that fails is a failure at the same row, not an end. */
	public function testALookAtTheFolderThatFailsIsAFailure(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('placeOf')->willThrowException(new \RuntimeException('database went away'));
		$clock = new FixedClock();

		$pass = (new FolderSessionRevoker($bindings, $this->createMock(GroupSessionRevoker::class), $this->createMock(IJobList::class), $this->createMock(LoggerInterface::class), $clock))->pass(self::FOLDER, 7);

		$this->assertSame(['after' => 7, 'ended' => false, 'failed' => true], $pass);
	}

	/**
	 * A folder restored during a run: its rows are no longer under where it
	 * was, so no later page queues them. What was queued before is for the
	 * group's job to leave be, a file back in Files (GroupSessionRevokerTest).
	 */
	public function testARestoreDuringARunQueuesNoMoreGroups(): void {
		// A page's rows in a hundred groups; the next page's each in its own.
		[$rows, $cached] = $this->folderOfRows(1000, 500, 100);
		[$moreRows, $moreCached] = $this->folderOfRows(1500, 100);
		$table = new InMemoryBindingTable([...$rows, ...$moreRows], [...$cached, ...$moreCached]);
		$queued = [];
		$restored = false;
		$restore = static function () use ($table, &$queued, &$restored): void {
			if (!$restored && count($queued) === 100) {
				$restored = true;
				foreach ($table->fileCache as &$row) {
					$row['path'] = str_replace(self::TRASHED, 'files/big', (string)$row['path']);
				}
			}
		};

		$pass = $this->revoker($table, $queued, onQueue: $restore)->pass(self::FOLDER, 0);

		$this->assertCount(100, $queued);
		$this->assertTrue($pass['ended']);
	}

	/** A group that could not be queued keeps the cursor before its row, so the next run meets it again. */
	public function testACursorDoesNotPassAGroupNotQueued(): void {
		$table = new InMemoryBindingTable([
			$this->row(11, 'one'),
			$this->row(12, 'two'),
			$this->row(13, 'three'),
		], [
			$this->cached(self::FOLDER, self::TRASHED),
			$this->cached(11, self::TRASHED . '/11.pad'),
			$this->cached(12, self::TRASHED . '/12.pad'),
			$this->cached(13, self::TRASHED . '/13.pad'),
		]);
		$queued = [];

		$pass = $this->revoker($table, $queued, refuses: 'g.two')->pass(self::FOLDER, 0);

		$this->assertSame(['g.one'], $queued);
		$this->assertSame(['after' => 11, 'ended' => false, 'failed' => true], $pass);
	}

	/** A folder the file cache no longer has, or that is back in Files, ends the job: nothing is queued. */
	public function testEndsForAFolderGoneOrBack(): void {
		$rows = [$this->row(11, 'one')];
		$file = $this->cached(11, 'files/big/11.pad');
		foreach ([
			'gone' => new InMemoryBindingTable($rows, [$file]),
			'back' => new InMemoryBindingTable($rows, [
				['fileid' => 1, 'storage' => self::STORAGE, 'path' => '', 'path_hash' => md5('')],
				$this->cached(self::FOLDER, 'files/big'),
				$file,
			], [['storage_id' => self::STORAGE, 'root_id' => 1, 'mount_point' => '/alice/', 'mount_provider_class' => 'OC\\Files\\Mount\\LocalHomeMountProvider']]),
		] as $case => $table) {
			$queued = [];

			$pass = $this->revoker($table, $queued)->pass(self::FOLDER, 5);

			$this->assertSame([], $queued, $case);
			$this->assertSame(['after' => 5, 'ended' => true, 'failed' => false], $pass, $case);
		}
	}

	/** What each way a pass ends says, under the app's name and the folder's id. */
	public function testSaysWhatCameOfTheFolder(): void {
		$rows = [$this->row(11, 'one')];
		$gone = new InMemoryBindingTable($rows, []);
		$back = new InMemoryBindingTable($rows, [
			['fileid' => 1, 'storage' => self::STORAGE, 'path' => '', 'path_hash' => md5('')],
			$this->cached(self::FOLDER, 'files/big'),
		], [['storage_id' => self::STORAGE, 'root_id' => 1, 'mount_point' => '/alice/', 'mount_provider_class' => 'OC\\Files\\Mount\\LocalHomeMountProvider']]);
		$found = new InMemoryBindingTable([$this->row(11, 'one'), $this->row(12, 'two')], [
			$this->cached(self::FOLDER, self::TRASHED),
			$this->cached(11, self::TRASHED . '/11.pad'),
			$this->cached(12, self::TRASHED . '/12.pad'),
		]);
		$context = ['app' => 'etherpad_nextcloud', 'fileId' => self::FOLDER];
		foreach ([
			'gone' => [$gone, 'A folder deleted has left the file cache; the sessions of the protected pads a delete did not reach expire on their own.', $context],
			'back' => [$back, 'A folder deleted is back in Files; its protected pads keep their sessions.', $context],
			'found' => [$found, 'Queued the revocation of the sessions of protected pads a delete of their folder did not reach.', $context + ['groups' => 2]],
		] as $case => [$table, $message, $expected]) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('info')->with($message, $expected);
			$queued = [];

			$this->revoker($table, $queued, logger: $logger)->pass(self::FOLDER, 0);
		}
	}

	/** No group queued, no line about any. */
	public function testSaysNothingOfGroupsWhereNoneWasQueued(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$queued = [];

		$this->revoker(new InMemoryBindingTable([], [$this->cached(self::FOLDER, self::TRASHED)]), $queued, logger: $logger)->pass(self::FOLDER, 0);
	}

	public function testQueueNamesTheFolderAndNeverFails(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('add')->with(RevokeFolderSessionsJob::class, ['folderId' => self::FOLDER]);
		$this->withJobList($jobList)->queue(self::FOLDER);

		$failing = $this->createMock(IJobList::class);
		$failing->method('add')->willThrowException(new \RuntimeException('database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === self::FOLDER && isset($context['error'])));
		$this->withJobList($failing, $logger)->queue(self::FOLDER);
	}

	/**
	 * @param list<string> $queued
	 * @param ?callable(): void $onQueue
	 */
	private function revoker(InMemoryBindingTable $table, array &$queued, ?string $refuses = null, ?TestClock $clock = null, ?callable $onQueue = null, ?LoggerInterface $logger = null): FolderSessionRevoker {
		$clock ??= new FixedClock();
		$groups = $this->createMock(GroupSessionRevoker::class);
		$groups->method('queue')->willReturnCallback(static function (string $groupId) use (&$queued, $refuses, $onQueue): bool {
			if ($groupId === $refuses) {
				return false;
			}
			$queued[] = $groupId;
			if ($onQueue !== null) {
				$onQueue();
			}
			return true;
		});
		return new FolderSessionRevoker(new BindingService($table, $clock), $groups, $this->createMock(IJobList::class), $logger ?? $this->createMock(LoggerInterface::class), $clock);
	}

	private function withJobList(IJobList $jobList, ?LoggerInterface $logger = null): FolderSessionRevoker {
		$clock = new FixedClock();
		return new FolderSessionRevoker(new BindingService(new InMemoryBindingTable([], []), $clock), $this->createMock(GroupSessionRevoker::class), $jobList, $logger ?? $this->createMock(LoggerInterface::class), $clock);
	}

	/**
	 * @param ?positive-int $groups how many groups the pads share; each its own without
	 * @return array{list<array<string,mixed>>,list<array<string,mixed>>} rows and file cache: $count pads in the folder
	 */
	private function folderOfRows(int $first, int $count, ?int $groups = null): array {
		$rows = [];
		$cached = [$this->cached(self::FOLDER, self::TRASHED)];
		for ($fileId = $first; $fileId < $first + $count; $fileId++) {
			$rows[] = $this->row($fileId, 'g' . ($groups === null ? $fileId : $fileId % $groups));
			$cached[] = $this->cached($fileId, self::TRASHED . '/' . $fileId . '.pad');
		}
		return [$rows, $cached];
	}

	/** @return array<string,mixed> */
	private function row(int $fileId, string $group, string $mode = BindingService::ACCESS_PROTECTED, string $state = BindingService::STATE_ACTIVE): array {
		$group = explode('$', $group)[0];
		return ['file_id' => $fileId, 'pad_id' => 'g.' . $group . '$p-' . $fileId, 'access_mode' => $mode, 'state' => $state];
	}

	/** @return array<string,mixed> */
	private function cached(int $fileId, string $path, int $storage = self::STORAGE): array {
		return ['fileid' => $fileId, 'storage' => $storage, 'path' => $path, 'path_hash' => md5($path)];
	}
}
