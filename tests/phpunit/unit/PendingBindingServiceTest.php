<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PendingBindingService;
use OCA\EtherpadNextcloud\Service\SettleOutcome;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PendingBindingServiceTest extends TestCase {
	/**
	 * Where a waiting row's file is now decides what happens to it: in
	 * Files it is settled as a restore would, in its owner's trash the
	 * snapshot the trash could not take is taken there, and gone for good
	 * its pad and row are deleted - for an undecided restore as much as for
	 * a deletion owed.
	 */
	public function testEachRowGoesByWhereItsFileIsNow(): void {
		$bindings = $this->bindings(
			restores: [
				$this->row(1, BindingService::STATE_RESTORE_PENDING, 'files/One.pad'),
				$this->row(5, BindingService::STATE_RESTORE_PENDING, null),
			],
			deletes: [
				$this->row(2, BindingService::STATE_PENDING_DELETE, 'files_trashbin/files/Two.pad.d100'),
				$this->row(3, BindingService::STATE_PENDING_DELETE, null),
				$this->row(4, BindingService::STATE_PENDING_DELETE, 'files/Four.pad'),
			],
		);
		$released = [];
		$bindings->method('deleteInState')->willReturnCallback(static function (int $fileId, string $padId, string $state) use (&$released): bool {
			$released[] = [$fileId, $state];
			return true;
		});
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getRevisionsCount')->willReturn(3);
		$deleted = [];
		$client->method('deletePad')->willReturnCallback(static function (string $padId) use (&$deleted): void {
			$deleted[] = $padId;
		});

		$settled = [];
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->method('settleWaitingFile')->willReturnCallback(static function (File $file) use (&$settled): SettleOutcome {
			$settled[] = $file->getId();
			return SettleOutcome::Settled;
		});
		$trashed = [];
		$lifecycle->method('finishTrash')->willReturnCallback(static function (File $file) use (&$trashed): SettleOutcome {
			$trashed[] = $file->getPath();
			return SettleOutcome::Settled;
		});

		$root = $this->root([
			1 => ['/alice/files/One.pad'],
			2 => ['/alice/files_trashbin/files/Two.pad.d100'],
			4 => ['/alice/files/Four.pad'],
		]);
		$result = $this->service($bindings, $lifecycle, $root, $client)->settleByAge(0, 3600, 50);

		$this->assertSame([1, 4], $settled);
		$this->assertSame(['/alice/files_trashbin/files/Two.pad.d100'], $trashed);
		$this->assertSame(['pad-5', 'pad-3'], $deleted);
		$this->assertSame([[5, BindingService::STATE_RESTORE_PENDING], [3, BindingService::STATE_PENDING_DELETE]], $released);
		$this->assertSame(['checked' => 5, 'settled' => 5], $result);
	}

	/**
	 * A trash is recognised by where it sits, not by a name appearing
	 * somewhere in a path: a team folder's trash is one too, and a folder a
	 * user called files_trashbin inside their files is not.
	 */
	public function testTellsATrashFromAFolderNamedLikeOne(): void {
		$bindings = $this->bindings(
			restores: [
				$this->row(1, BindingService::STATE_RESTORE_PENDING, 'files/files_trashbin/Notes.pad'),
				$this->row(2, BindingService::STATE_RESTORE_PENDING, 'files/Elsewhere.pad'),
			],
			deletes: [$this->row(3, BindingService::STATE_PENDING_DELETE, '__groupfolders/trash/7/Team.pad.d100')],
		);
		$settled = [];
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->method('settleWaitingFile')->willReturnCallback(static function (File $file) use (&$settled): SettleOutcome {
			$settled[] = $file->getId();
			return SettleOutcome::Settled;
		});
		$lifecycle->expects($this->never())->method('handleTrash');

		$root = $this->root([
			1 => ['/alice/files/files_trashbin/Notes.pad'],
			// Found in a trash after all, where the file cache said otherwise.
			2 => ['/alice/files_trashbin/files/Elsewhere.pad.d100'],
			3 => ['/alice/files/Team/Team.pad'],
		], $lookedUp);
		$this->service($bindings, $lifecycle, $root)->settleByAge(0, null, 50);

		$this->assertSame([1], $settled);
		// The file cache said trash, so the file was not even looked for.
		$this->assertSame([1, 2], $lookedUp);
	}

	/**
	 * A file is found through every mount it is in, and the first one
	 * outside a trash is taken. A sweep only reads the file, so a share
	 * that cannot be written serves as well as the owner's own.
	 */
	public function testReadsTheFileThroughAMountOutsideTheTrash(): void {
		$bindings = $this->bindings(restores: [$this->row(1, BindingService::STATE_RESTORE_PENDING, 'files/One.pad')]);
		$seen = [];
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->method('settleWaitingFile')->willReturnCallback(static function (File $file) use (&$seen): SettleOutcome {
			$seen[] = $file->getPath();
			return SettleOutcome::Settled;
		});

		$this->service($bindings, $lifecycle, $this->root([1 => ['/bob/files_trashbin/files/One.pad.d9', '/alice/files/One.pad']]))
			->settleByAge(0, null, 50);

		$this->assertSame(['/alice/files/One.pad'], $seen);
	}

	/**
	 * Rows stay until they are settled, so an Etherpad that is down would
	 * otherwise cost every run a full timeout per row. A few without an
	 * answer are read as an outage, and the run ends there - but only
	 * Etherpad's silence counts: rows left for other reasons do not.
	 */
	public function testARunEndsAfterAFewRowsEtherpadGaveNoAnswerFor(): void {
		foreach ([[SettleOutcome::Unanswered, 5], [SettleOutcome::Left, 8]] as [$case, $expected]) {
			$rows = [];
			$paths = [];
			for ($id = 1; $id <= 8; $id++) {
				$rows[] = $this->row($id, BindingService::STATE_RESTORE_PENDING, 'files/' . $id . '.pad');
				$paths[$id] = ['/alice/files/' . $id . '.pad'];
			}
			$lifecycle = $this->createMock(LifecycleService::class);
			$lifecycle->expects($this->exactly($expected))->method('settleWaitingFile')->willReturn($case);

			$this->service($this->bindings(restores: $rows), $lifecycle, $this->root($paths))->settleByAge(0, null, 50);
		}
	}

	/**
	 * Each Etherpad call gets what is left of the run, and a row that could
	 * not finish inside it is not started.
	 */
	public function testEachCallIsCutToWhatIsLeftOfTheRun(): void {
		$clock = new FixedClock();
		$rows = [];
		$paths = [];
		for ($id = 1; $id <= 3; $id++) {
			$rows[] = $this->row($id, BindingService::STATE_RESTORE_PENDING, 'files/' . $id . '.pad');
			$paths[$id] = ['/alice/files/' . $id . '.pad'];
		}
		$timeouts = [];
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->method('settleWaitingFile')->willReturnCallback(static function (File $file, ?int $timeout) use ($clock, &$timeouts): SettleOutcome {
			$timeouts[] = $timeout;
			$clock->advance(10);
			return SettleOutcome::Settled;
		});

		$this->service($this->bindings(restores: $rows), $lifecycle, $this->root($paths), clock: $clock)->settleByAge(0, null, 50);

		$this->assertSame([15, 10], $timeouts);
	}

	/**
	 * Deleting a group pad asks Etherpad what the group holds, then takes
	 * it: each of those calls gets what is left of the run too, not the
	 * client's own timeout.
	 */
	public function testTheDeletionsCallsStayInsideTheRun(): void {
		$bindings = $this->bindings(deletes: [$this->row(3, BindingService::STATE_PENDING_DELETE, null, 'g.ABCDEFGHIJKLMNOP$p-gone')]);
		$bindings->method('deleteInState')->willReturn(true);
		$calls = [];
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getRevisionsCount')->willReturnCallback(static function (string $padId, ?int $timeout) use (&$calls): int {
			$calls['getRevisionsCount'] = $timeout;
			return 3;
		});
		$client->method('listPads')->willReturnCallback(static function (string $groupId, ?int $timeout) use (&$calls): array {
			$calls['listPads'] = $timeout;
			return ['g.ABCDEFGHIJKLMNOP$p-gone'];
		});
		$client->method('deleteGroup')->willReturnCallback(static function (string $groupId, ?int $timeout) use (&$calls): void {
			$calls['deleteGroup'] = $timeout;
		});

		$this->service($bindings, $this->createMock(LifecycleService::class), $this->root([]), $client)->settleByAge(0, null, 50);

		$this->assertSame(['getRevisionsCount' => 15, 'listPads' => 15, 'deleteGroup' => 15], $calls);
	}

	/**
	 * The deletion goes by what Etherpad says: a pad it no longer has only
	 * takes the row, no answer leaves both, and with deleting on trash
	 * switched off nothing is asked or taken at all.
	 */
	public function testAnOwedDeletionGoesByEtherpadsAnswerAndTheSetting(): void {
		foreach (['gone' => ['padID does not exist', true], 'no answer' => ['Connection refused', true], 'setting off' => [3, false]] as $case => [$answer, $enabled]) {
			$bindings = $this->bindings(deletes: [$this->row(3, BindingService::STATE_PENDING_DELETE, null)]);
			$bindings->expects($case === 'gone' ? $this->once() : $this->never())->method('deleteInState')->willReturn(true);
			$client = $this->createMock(EtherpadClient::class);
			$client->expects($case === 'setting off' ? $this->never() : $this->once())
				->method('getRevisionsCount')
				->willReturnCallback(static function () use ($answer): int {
					return is_int($answer) ? $answer : throw new \RuntimeException($answer);
				});
			$client->expects($this->never())->method('deletePad');

			$result = $this->service($bindings, $this->createMock(LifecycleService::class), $this->root([]), $client, deleteOnTrash: $enabled)
				->settleByAge(0, null, 50);

			$this->assertSame($case === 'gone' ? 1 : 0, $result['settled'], $case);
		}
	}

	/**
	 * A failure of the database or a storage says nothing about Etherpad,
	 * so however many rows meet one, the run does not read it as an outage
	 * and the rows behind them still get their turn.
	 */
	public function testLocalFailuresDoNotEndTheRun(): void {
		$rows = [];
		$paths = [];
		for ($id = 1; $id <= 8; $id++) {
			$rows[] = $this->row($id, BindingService::STATE_RESTORE_PENDING, 'files/' . $id . '.pad');
			$paths[$id] = ['/alice/files/' . $id . '.pad'];
		}
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->expects($this->exactly(8))
			->method('settleWaitingFile')
			->willThrowException(new \RuntimeException('database went away'));

		$this->service($this->bindings(restores: $rows), $lifecycle, $this->root($paths))->settleByAge(0, null, 50);
	}

	/**
	 * The limit counts every row together: restores first, then deletions
	 * owed by kind - gone for good, in a user's trash, anywhere else - each
	 * from what is left, so rows that wait on a trash cannot crowd out the
	 * ones that can go.
	 */
	public function testTheLimitCountsBothKindsTogether(): void {
		foreach ([2 => [], 5 => [[BindingService::FILE_GONE, 3], [BindingService::FILE_IN_USER_TRASH, 2], [BindingService::FILE_ELSEWHERE, 1]]] as $limit => $expected) {
			$bindings = $this->createMock(BindingService::class);
			$bindings->method('findRestorePendingByAge')->willReturn([
				$this->row(1, BindingService::STATE_RESTORE_PENDING, 'files/1.pad'),
				$this->row(2, BindingService::STATE_RESTORE_PENDING, 'files/2.pad'),
			]);
			$asked = [];
			$bindings->method('findPendingDeleteByAge')->willReturnCallback(function (int $min, ?int $max, int $limit, ?string $fileLocation) use (&$asked): array {
				$asked[] = [$fileLocation, $limit];
				return [$this->row(10 + count($asked), BindingService::STATE_PENDING_DELETE, 'files/x.pad')];
			});

			$this->service($bindings, $this->createMock(LifecycleService::class), $this->root([]))->settleByAge(0, null, $limit);

			$this->assertSame($expected, $asked, "limit $limit");
		}
	}

	/**
	 * A run whose time is spent by the time the pad should go stops there:
	 * no call is started that could not finish, and the row waits for the
	 * next run rather than being taken without its pad.
	 */
	public function testADeletionOutOfTimeLeavesPadAndRow(): void {
		$clock = new FixedClock();
		$bindings = $this->bindings(deletes: [$this->row(3, BindingService::STATE_PENDING_DELETE, null, 'g.ABCDEFGHIJKLMNOP$p-gone')]);
		$bindings->expects($this->never())->method('deleteInState');
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getRevisionsCount')->willReturnCallback(static function () use ($clock): int {
			$clock->advance(19);
			return 3;
		});
		$client->expects($this->never())->method('listPads');
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->never())->method('deletePad');

		$result = $this->service($bindings, $this->createMock(LifecycleService::class), $this->root([]), $client, clock: $clock)
			->settleByAge(0, null, 50);

		$this->assertSame(['checked' => 1, 'settled' => 0], $result);
	}

	/** A row that throws is logged and counted, and the rows behind it still get their turn. */
	public function testARowThatThrowsDoesNotStopTheRest(): void {
		$bindings = $this->bindings(restores: [
			$this->row(1, BindingService::STATE_RESTORE_PENDING, 'files/1.pad'),
			$this->row(2, BindingService::STATE_RESTORE_PENDING, 'files/2.pad'),
		]);
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->method('settleWaitingFile')->willReturnCallback(static function (File $file): SettleOutcome {
			if ($file->getId() === 1) {
				throw new \RuntimeException('database went away');
			}
			return SettleOutcome::Settled;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not settle a pad binding that waits.', $this->anything());

		$result = $this->service($bindings, $lifecycle, $this->root([1 => ['/a/files/1.pad'], 2 => ['/a/files/2.pad']]), logger: $logger)
			->settleByAge(0, null, 50);

		$this->assertSame(['checked' => 2, 'settled' => 1], $result);
	}

	/** The admin page shows what a run did and what is left of either kind. */
	public function testSettleReportsWhatIsLeft(): void {
		$bindings = $this->bindings();
		$bindings->method('countWaiting')->willReturn(['pending_delete_count' => 4, 'restore_pending_count' => 1]);

		$result = $this->service($bindings, $this->createMock(LifecycleService::class), $this->root([]))->settle(50);

		$this->assertSame(['checked' => 0, 'settled' => 0, 'pending_delete_count' => 4, 'restore_pending_count' => 1], $result);
	}

	/** @return array<string,mixed> */
	private function row(int $fileId, string $state, ?string $path, ?string $padId = null): array {
		return ['file_id' => $fileId, 'pad_id' => $padId ?? 'pad-' . $fileId, 'state' => $state, 'file_path' => $path];
	}

	/**
	 * @param list<array<string,mixed>> $restores
	 * @param list<array<string,mixed>> $deletes
	 */
	private function bindings(array $restores = [], array $deletes = []): BindingService&MockObject {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('findRestorePendingByAge')->willReturn($restores);
		// Each kind answered from the rows' file paths, as the query narrows them.
		$bindings->method('findPendingDeleteByAge')->willReturnCallback(
			static fn (int $min, ?int $max, int $limit, ?string $fileLocation = null): array => array_values(array_filter(
				$deletes,
				static fn (array $row): bool => match ($fileLocation) {
					BindingService::FILE_GONE => $row['file_path'] === null,
					BindingService::FILE_IN_USER_TRASH => str_starts_with((string)$row['file_path'], 'files_trashbin/'),
					BindingService::FILE_ELSEWHERE => $row['file_path'] !== null && !str_starts_with((string)$row['file_path'], 'files_trashbin/'),
					default => true,
				},
			)),
		);
		return $bindings;
	}

	/**
	 * @param array<int,list<string>> $paths every path a file id is found under, in mount order
	 * @param list<int> $lookedUp filled with the ids asked for
	 */
	private function root(array $paths, ?array &$lookedUp = null): IRootFolder {
		$lookedUp = [];
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(function (int $id) use ($paths, &$lookedUp): array {
			$lookedUp[] = $id;
			return array_map(function (string $path) use ($id): File {
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn($id);
				$file->method('getPath')->willReturn($path);
				return $file;
			}, $paths[$id] ?? []);
		});
		return $root;
	}

	private function service(
		BindingService $bindings,
		LifecycleService $lifecycle,
		IRootFolder $root,
		?EtherpadClient $client = null,
		bool $deleteOnTrash = true,
		?FixedClock $clock = null,
		?LoggerInterface $logger = null,
	): PendingBindingService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'delete_on_trash' ? ($deleteOnTrash ? 'yes' : 'no') : $default,
		);
		return new PendingBindingService(
			$bindings,
			$lifecycle,
			new ManagedPadLifecycle($client ?? $this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
			$root,
			$config,
			$logger ?? $this->createMock(LoggerInterface::class),
			$clock ?? new FixedClock(),
		);
	}
}
