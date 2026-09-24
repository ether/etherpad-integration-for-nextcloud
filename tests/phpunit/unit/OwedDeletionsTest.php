<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\OwedDeletions;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Service\SettleOutcome;
use OCA\EtherpadNextcloud\Service\TestFaults;
use OCA\EtherpadNextcloud\Service\TrashSnapshotWriters;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Service\WaitingBinding;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\PadFiles;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The deletions a trash owed, carried out: a trashed file's snapshot first,
 * then row and pad; a file gone for good, pad and row. Each answers how it
 * left the row, which the sweep counts against its run - Settled, Left, or
 * Unanswered when Etherpad gave none.
 */
class OwedDeletionsTest extends TestCase {
	use PadFiles;

	/**
	 * A trashed file that already holds the pad's revision needs no new
	 * snapshot: the pad goes without a fetch or a write, in the sweep as
	 * much as at trash time.
	 */
	public function testNoSnapshotIsTakenThatTheTrashedFileHasAlready(): void {
		$bindingService = $this->pendingTrashRow(111, 'pad-current');
		$bindingService->expects($this->once())->method('deleteInState')->willReturn(true);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('getRevisionsCount')->willReturn(4);
		$etherpadClient->expects($this->never())->method('getText');
		$etherpadClient->expects($this->once())->method('deletePad');
		$file = $this->trashedFile(111, snapshotRev: 4);
		$file->expects($this->never())->method('putContent');

		$this->assertSame(SettleOutcome::Settled, $this->owed($bindingService, $etherpadClient)->finishTrash($file, new RunBudget(new FixedClock(), 20.0)));
	}

	/**
	 * The sweep's half of a trash: the file sits in its owner's trash, and
	 * the pad's content goes into it before row and pad go. The row goes
	 * first: it is what a restore takes the pad back by. A restore after
	 * that makes a new pad from this snapshot.
	 */
	public function testFinishTrashWritesTheSnapshotThenTakesTheRowBeforeThePad(): void {
		$order = [];
		$bindingService = $this->pendingTrashRow(111, 'pad-trashed');
		$bindingService->expects($this->once())
			->method('deleteInState')
			->with(111, 'pad-trashed', BindingService::STATE_PENDING_DELETE)
			->willReturnCallback(static function () use (&$order): bool {
				$order[] = 'row';
				return true;
			});
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(5);
		$etherpadClient->method('getText')->willReturn('the unsynced edit');
		$etherpadClient->expects($this->once())->method('deletePad')->with('pad-trashed')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'pad';
		});
		$file = $this->trashedFile(111);
		$file->expects($this->once())->method('putContent')->with('doc-after')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'snapshot';
		});

		$outcome = $this->owed($bindingService, $etherpadClient)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

		$this->assertSame(SettleOutcome::Settled, $outcome);
		$this->assertSame(['snapshot', 'row', 'pad'], $order);
	}

	/**
	 * A restore that took the row between the sweep's read and its delete
	 * has the pad: the sweep's delete finds no row in pending_delete, and
	 * the pad is not touched.
	 */
	public function testARestoreThatTookTheRowKeepsThePad(): void {
		$bindingService = $this->pendingTrashRow(111, 'pad-back');
		$bindingService->expects($this->once())->method('deleteInState')->willReturn(false);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(5);
		$etherpadClient->expects($this->never())->method('deletePad');

		$outcome = $this->owed($bindingService, $etherpadClient)->finishTrash($this->trashedFile(111), new RunBudget(new FixedClock(), 20.0));

		$this->assertSame(SettleOutcome::Left, $outcome);
	}

	/**
	 * A restore that took the file back while its snapshot was written
	 * leaves row and pad to the restore, which takes the pad back. What the
	 * write may have made in the trash where the file was goes, so the
	 * trash lists no second copy; one that cannot be removed is logged.
	 *
	 * Both are asked by the id and path read before the write, which the
	 * file answers differently after it (UserNodeResolver::hasMoved()).
	 */
	public function testAFileRestoredWhileWrittenKeepsRowAndPadAndClearsTheCopy(): void {
		foreach (['copy removed' => null, 'copy left' => new \RuntimeException('storage gone')] as $case => $removeError) {
			$bindingService = $this->pendingTrashRow(111, 'pad-back');
			$bindingService->expects($this->never())->method('deleteInState');
			$bindingService->expects($this->never())->method('transition');
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturn(5);
			$etherpadClient->method('getText')->willReturn('the unsynced edit');
			$etherpadClient->expects($this->never())->method('deletePad');
			$written = false;
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturnCallback(static function () use (&$written): int {
				return $written ? 112 : 111;
			});
			$file->method('getName')->willReturn('Trashed.pad.d100');
			$file->method('getPath')->willReturn('/alice/files_trashbin/files/Trashed.pad.d100');
			$file->method('getContent')->willReturn('doc-before');
			$file->expects($this->once())->method('putContent')->willReturnCallback(static function () use (&$written): void {
				$written = true;
			});
			$nodes = $this->createMock(UserNodeResolver::class);
			// Still there right before the write, gone right after it.
			$nodes->method('hasMoved')->with(111, '/alice/files_trashbin/files/Trashed.pad.d100')->willReturnOnConsecutiveCalls(false, true);
			$removal = $nodes->expects($this->once())->method('removeStrayCopy')->with(111, '/alice/files_trashbin/files/Trashed.pad.d100');
			if ($removeError !== null) {
				$removal->willThrowException($removeError);
			}
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($removeError === null ? $this->never() : $this->once())
				->method('warning')
				->with(
					'Could not remove the copy a sweep wrote into the trash after its file was restored.',
					$this->callback(static fn (array $context): bool => $context['fileId'] === 111 && $context['error_message'] === 'storage gone'),
				);

			$outcome = $this->owed($bindingService, $etherpadClient, logger: $logger, nodes: $nodes)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

			$this->assertSame(SettleOutcome::Left, $outcome, $case);
		}
	}

	/**
	 * Past the row, a pad that cannot be deleted is left over, and nothing
	 * leads to it any more: its id goes into the log. Its content is in the
	 * file, so the row is settled all the same.
	 */
	public function testAPadLeftOverPastItsRowIsLoggedWithItsId(): void {
		$bindingService = $this->pendingTrashRow(111, 'pad-left');
		$bindingService->expects($this->once())->method('deleteInState')->willReturn(true);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(5);
		$etherpadClient->method('deletePad')->willThrowException(new \RuntimeException('Connection reset'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Could not delete a pad whose binding is gone. It is left over.',
			$this->callback(static fn (array $context): bool => $context['padId'] === 'pad-left' && $context['fileId'] === 111),
		);

		$outcome = $this->owed($bindingService, $etherpadClient, logger: $logger)->finishTrash($this->trashedFile(111), new RunBudget(new FixedClock(), 20.0));

		$this->assertSame(SettleOutcome::Settled, $outcome);
	}

	/** No row is taken when no call would fit in the run any more: both wait for the next one. */
	public function testNoRowIsTakenWithoutTimeToDeleteThePad(): void {
		$clock = new FixedClock();
		$bindingService = $this->pendingTrashRow(111, 'pad-late');
		$bindingService->expects($this->never())->method('deleteInState');
		$etherpadClient = $this->slowCount($clock, 19, 4);
		$etherpadClient->expects($this->never())->method('deletePad');

		$outcome = $this->owed($bindingService, $etherpadClient)->finishTrash($this->trashedFile(111, snapshotRev: 4), new RunBudget($clock, 20.0));

		$this->assertSame(SettleOutcome::Left, $outcome);
	}

	/**
	 * Once its row is gone, a group pad goes whole whatever the run has
	 * left: stopping between asking what the group holds and deleting it
	 * would leave group, pad and sessions behind for good. Each call still
	 * has the client's own timeout.
	 */
	public function testAGroupPadGoesWholeOnceItsRowIsTaken(): void {
		$clock = new FixedClock();
		$padId = 'g.ABCDEFGHIJKLMNOP$p-trashed';
		$bindingService = $this->pendingTrashRow(117, $padId);
		$bindingService->expects($this->once())->method('deleteInState')->willReturn(true);
		$calls = [];
		$etherpadClient = $this->slowCount($clock, 17, 4);
		$etherpadClient->method('listPads')->willReturnCallback(static function (string $groupId, ?int $timeout) use ($clock, $padId, &$calls): array {
			$calls['listPads'] = $timeout;
			$clock->advance(5);
			return [$padId];
		});
		$etherpadClient->expects($this->once())->method('deleteGroup')->willReturnCallback(static function (string $groupId, ?int $timeout) use (&$calls): void {
			$calls['deleteGroup'] = $timeout;
		});

		$outcome = $this->owed($bindingService, $etherpadClient)->finishTrash($this->trashedFile(117, snapshotRev: 4), new RunBudget($clock, 20.0));

		$this->assertSame(SettleOutcome::Settled, $outcome);
		$this->assertSame(['listPads' => null, 'deleteGroup' => null], $calls);
	}

	/**
	 * Held to the file's snapshot revision like a restore. A pad behind it
	 * is not the file's and a pad that is gone has nothing to give: the row
	 * is released, the file keeps the snapshot it has, and a pad someone may
	 * have written into is not touched. A public pad Etherpad has just
	 * called absent takes no further call. A pad that changes while it is
	 * read is the writer's (TrashSnapshotWriterTest).
	 */
	public function testFinishTrashGoesByWhatEtherpadSays(): void {
		foreach (['behind' => 2, 'gone' => 'padID does not exist'] as $case => $answer) {
			$bindingService = $this->pendingTrashRow(112, 'pad-trashed');
			$bindingService->expects($this->once())->method('deleteInState')->willReturn(true);
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturnCallback(static fn (): int => is_int($answer) ? $answer : throw new \RuntimeException($answer));
			$etherpadClient->expects($this->never())->method('deletePad');
			$file = $this->trashedFile(112, snapshotRev: 3);
			$file->expects($this->never())->method('putContent');

			$outcome = $this->owed($bindingService, $etherpadClient)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

			$this->assertSame(SettleOutcome::Settled, $outcome, $case);
		}
	}

	/**
	 * Etherpad giving no answer - to the question, while the snapshot is
	 * read, or to the count after the write - is the same silence wherever
	 * it is met: it counts towards the run's stop, and row and pad stay
	 * where they are. Only a file's own trouble moves a row back. Silence the
	 * writer meets is its miss, in its log line.
	 */
	public function testEtherpadSilenceIsNoAnswerWhereverItIsMet(): void {
		$cases = [
			// The count that goes unanswered - the question is the first - or the text, whether the file was written, and the writer's reason.
			'to the question' => [1, false, false, null],
			'while the snapshot is read' => [null, true, false, 'snapshot_not_fetched'],
			'to the count after the write' => [3, false, true, 'snapshot_not_fetched'],
		];
		foreach ($cases as $case => [$silentCount, $silentText, $written, $reason]) {
			$bindingService = $this->pendingTrashRow(120, 'pad-f');
			$bindingService->expects($this->never())->method('transition');
			$bindingService->expects($this->never())->method('deleteInState');
			$counts = 0;
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function () use (&$counts, $silentCount): int {
				return ++$counts === $silentCount ? throw new EtherpadClientException('Operation timed out') : 5;
			});
			if ($silentText) {
				$etherpadClient->method('getText')->willThrowException(new EtherpadClientException('Operation timed out'));
			}
			$etherpadClient->expects($this->never())->method('deletePad');
			$file = $this->trashedFile(120);
			$file->expects($written ? $this->once() : $this->never())->method('putContent');
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($reason === null ? $this->never() : $this->once())->method('warning')->with(
				'A trashed .pad file did not get its snapshot. Its pad is kept for now.',
				$this->callback(static fn (array $context): bool => $context['reason'] === $reason),
			);

			$outcome = $this->owed($bindingService, $etherpadClient, logger: $logger)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

			$this->assertSame(SettleOutcome::Unanswered, $outcome, $case);
		}
	}

	/** A row that is not a deletion owed any more, or a file that cannot be read, is left for later. */
	public function testFinishTrashLeavesWhatItCannotSettle(): void {
		$active = $this->createMock(BindingService::class);
		$active->method('findByFileId')->willReturn(new Binding(fileId: 113, padId: 'pad-a', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
		$active->expects($this->never())->method('deleteInState');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('getRevisionsCount');

		$this->assertSame(SettleOutcome::Left, $this->owed($active, $etherpadClient)->finishTrash($this->trashedFile(113), new RunBudget(new FixedClock(), 20.0)));

		$pending = $this->pendingTrashRow(114, 'pad-b');
		$pending->expects($this->never())->method('deleteInState');
		$locked = $this->createMock(File::class);
		$locked->method('getId')->willReturn(114);
		$locked->method('getContent')->willThrowException(new LockedException('Locked.pad'));

		$this->assertSame(SettleOutcome::Left, $this->owed($pending, $etherpadClient)->finishTrash($locked, new RunBudget(new FixedClock(), 20.0)));

		// Switched off after the trash: the pad stays, as the setting says now.
		$switchedOff = $this->pendingTrashRow(115, 'pad-c');
		$switchedOff->expects($this->never())->method('deleteInState');
		$etherpadClient->expects($this->never())->method('deletePad');
		$file = $this->trashedFile(115);
		$file->expects($this->never())->method('putContent');

		$this->assertSame(SettleOutcome::Left, $this->owed($switchedOff, $etherpadClient, deleteOnTrash: false)->finishTrash($file, new RunBudget(new FixedClock(), 20.0)));
	}

	/**
	 * A trashed file the sweep cannot use is tried again each run, from the
	 * back of the queue, but reported at warning level only the first time.
	 * When a row counts as reported is Binding::untouchedSinceOwed(); here,
	 * that its answer reaches the log line.
	 */
	public function testATrashedFileThatCannotBeReadIsReportedOnce(): void {
		$cases = [
			'first time' => [100, 100, 'warning'],
			'reported before' => [100, 400, 'debug'],
		];
		foreach ($cases as $case => [$deletedAt, $updatedAt, $level]) {
			$bindingService = $this->pendingTrashRow(116, 'pad-d', updatedAt: $updatedAt, deletedAt: $deletedAt);
			$bindingService->expects($this->once())
				->method('transition')
				->with(116, 'pad-d', BindingService::STATE_PENDING_DELETE, BindingService::STATE_PENDING_DELETE)
				->willReturn(true);
			$bindingService->expects($this->never())->method('deleteInState');
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn(116);
			$file->method('getContent')->willThrowException(new \RuntimeException('no key for this user'));
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method($level)->with(
				'A trashed .pad file did not get its snapshot. Its pad is kept for now.',
				$this->callback(static fn (array $context): bool => $context['reason'] === 'file_unreadable'),
			);
			$logger->expects($this->never())->method($level === 'warning' ? 'debug' : 'warning');

			$outcome = $this->owed($bindingService, $this->createMock(EtherpadClient::class), logger: $logger)
				->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

			$this->assertSame(SettleOutcome::Left, $outcome, $case);
		}
	}

	/**
	 * What passes by itself - a lock, a file a restore moved - is tried
	 * again where the row is: moving it back would count it as reported,
	 * and the file's own trouble met later would go to debug. An empty file
	 * waits for its trash, and moves back like the file's trouble.
	 *
	 * A restore can take the file back while its snapshot is fetched; the
	 * sweep asks right before the write, since one through the node it read
	 * would make a new file in the trash. That nothing is written then is
	 * the writer's (TrashSnapshotWriterTest).
	 */
	public function testOnlyTheFilesOwnTroubleMovesTheRowBack(): void {
		foreach (['file locked' => false, 'file moved' => false, 'file empty' => true] as $case => $movesBack) {
			$bindingService = $this->pendingTrashRow(119, 'pad-e');
			$bindingService->expects($movesBack ? $this->once() : $this->never())->method('transition');
			$bindingService->expects($this->never())->method('deleteInState');
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturn(5);
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn(119);
			match ($case) {
				'file locked' => $file->method('getContent')->willThrowException(new LockedException('Locked.pad')),
				'file empty' => $file->method('getContent')->willReturn(''),
				default => $file->method('getContent')->willReturn('doc-before'),
			};
			$nodes = $this->createMock(UserNodeResolver::class);
			$nodes->method('hasMoved')->with(119)->willReturn($case === 'file moved');
			// Moved before anything was written: whatever is at its old path is not the sweep's.
			$nodes->expects($this->never())->method('removeStrayCopy');

			$outcome = $this->owed($bindingService, $etherpadClient, nodes: $nodes)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

			$this->assertSame(SettleOutcome::Left, $outcome, $case);
		}
	}

	/**
	 * A file gone for good takes its pad first, then its row, in the state
	 * the sweep fetched it in - an undecided restore's too: no restore can
	 * come for it, and a pad that could not be deleted keeps its row, so the
	 * next run tries again. What Etherpad says decides the rest; with
	 * deleting on trash switched off nothing is asked or taken at all. A
	 * public pad Etherpad has just called absent takes no further call; a
	 * group pad does, for its empty group.
	 */
	public function testAGoneFilesDeletionGoesByEtherpadsAnswerAndTheSetting(): void {
		$groupPad = 'g.ABCDEFGHIJKLMNOP$p-gone';
		$cases = [
			'there' => ['pad-gone', 3, true, SettleOutcome::Settled, ['pad', 'row']],
			'there, an undecided restore' => ['pad-gone', 3, true, SettleOutcome::Settled, ['pad', 'row']],
			'gone' => ['pad-gone', 'padID does not exist', true, SettleOutcome::Settled, ['row']],
			'gone, a group pad' => [$groupPad, 'padID does not exist', true, SettleOutcome::Settled, ['group', 'row']],
			'no answer' => ['pad-gone', 'Connection refused', true, SettleOutcome::Unanswered, []],
			'delete refused' => ['pad-gone', 3, true, SettleOutcome::Unanswered, ['pad']],
			'setting off' => ['pad-gone', 3, false, SettleOutcome::Left, []],
		];
		foreach ($cases as $case => [$padId, $answer, $enabled, $expected, $steps]) {
			$order = [];
			$state = $case === 'there, an undecided restore' ? BindingService::STATE_RESTORE_PENDING : BindingService::STATE_PENDING_DELETE;
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->method('deleteInState')->willReturnCallback(static function (int $fileId, string $padId, string $inState) use (&$order, $state): bool {
				$order[] = $inState === $state ? 'row' : 'row in ' . $inState;
				return true;
			});
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->expects($enabled ? $this->once() : $this->never())
				->method('getRevisionsCount')
				->willReturnCallback(static fn (): int => is_int($answer) ? $answer : throw new \RuntimeException($answer));
			$etherpadClient->method('deletePad')->willReturnCallback(static function () use (&$order, $case, $answer): void {
				$order[] = 'pad';
				if ($case === 'delete refused') {
					throw new \RuntimeException('Connection reset');
				}
				if (!is_int($answer)) {
					throw new \RuntimeException($answer);
				}
			});
			$etherpadClient->method('listPads')->willReturn([]);
			$etherpadClient->method('deleteGroup')->willReturnCallback(static function () use (&$order): void {
				$order[] = 'group';
			});

			$outcome = $this->owed($bindingService, $etherpadClient, deleteOnTrash: $enabled)
				->finishGoneFile(self::goneRow(120, $padId, $state), new RunBudget(new FixedClock(), 20.0));

			$this->assertSame($expected, $outcome, $case);
			$this->assertSame($steps, $order, $case);
		}
	}

	/**
	 * Deleting a group pad asks Etherpad what the group holds, then takes
	 * it: each of those calls gets what is left of the run too, not the
	 * client's own timeout.
	 */
	public function testTheDeletionsCallsStayInsideTheRun(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('deleteInState')->willReturn(true);
		$calls = [];
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function (string $padId, ?int $timeout) use (&$calls): int {
			$calls['getRevisionsCount'] = $timeout;
			return 3;
		});
		$etherpadClient->method('listPads')->willReturnCallback(static function (string $groupId, ?int $timeout) use (&$calls): array {
			$calls['listPads'] = $timeout;
			return ['g.ABCDEFGHIJKLMNOP$p-gone'];
		});
		$etherpadClient->method('deleteGroup')->willReturnCallback(static function (string $groupId, ?int $timeout) use (&$calls): void {
			$calls['deleteGroup'] = $timeout;
		});

		$this->owed($bindingService, $etherpadClient)
			->finishGoneFile(self::goneRow(121, 'g.ABCDEFGHIJKLMNOP$p-gone'), new RunBudget(new FixedClock(), 20.0));

		$this->assertSame(['getRevisionsCount' => 15, 'listPads' => 15, 'deleteGroup' => 15], $calls);
	}

	/**
	 * A run whose time is spent by the time the pad should go stops there:
	 * no call is started that could not finish, and the row waits for the
	 * next run rather than being taken without its pad.
	 */
	public function testADeletionOutOfTimeLeavesPadAndRow(): void {
		$clock = new FixedClock();
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('deleteInState');
		$etherpadClient = $this->slowCount($clock, 19, 3);
		$etherpadClient->expects($this->never())->method('listPads');
		$etherpadClient->expects($this->never())->method('deleteGroup');
		$etherpadClient->expects($this->never())->method('deletePad');

		$outcome = $this->owed($bindingService, $etherpadClient)
			->finishGoneFile(self::goneRow(122, 'g.ABCDEFGHIJKLMNOP$p-gone'), new RunBudget($clock, 20.0));

		$this->assertSame(SettleOutcome::Left, $outcome);
	}

	/**
	 * A run whose time went into reading the file asks Etherpad nothing
	 * more: no call is started that could not finish, and the row keeps its
	 * place for the next run.
	 */
	public function testNoCallIsStartedOnceTheReadTookTheRunsTime(): void {
		$clock = new FixedClock();
		$slowRead = function (int $fileId) use ($clock): File&MockObject {
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn($fileId);
			$file->method('getName')->willReturn('Slow.pad');
			$file->method('getContent')->willReturnCallback(static function () use ($clock): string {
				$clock->advance(19);
				return 'doc-before';
			});
			return $file;
		};
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('getRevisionsCount');

		$trashRow = $this->pendingTrashRow(123, 'pad-h');
		$trashRow->expects($this->never())->method('transition');
		$this->assertSame(SettleOutcome::Left, $this->owed($trashRow, $etherpadClient)->finishTrash($slowRead(123), new RunBudget($clock, 20.0)));
	}

	/** Owed since $deletedAt; $updatedAt past that once a run moved it back. */
	private function pendingTrashRow(int $fileId, string $padId, int $updatedAt = 100, ?int $deletedAt = 100): BindingService&MockObject {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(new Binding(fileId: $fileId, padId: $padId, accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_PENDING_DELETE, deletedAt: $deletedAt, updatedAt: $updatedAt));
		return $bindingService;
	}

	/** A waiting row whose file has no file cache row left, as the sweep fetches it. */
	private static function goneRow(int $fileId, string $padId, string $state = BindingService::STATE_PENDING_DELETE): WaitingBinding {
		return new WaitingBinding($fileId, $padId, $state, null);
	}

	/** An Etherpad whose count takes $seconds of the run on $clock, then answers $revisions. */
	private function slowCount(FixedClock $clock, int $seconds, int $revisions): EtherpadClient&MockObject {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function () use ($clock, $seconds, $revisions): int {
			$clock->advance($seconds);
			return $revisions;
		});
		return $etherpadClient;
	}

	/**
	 * With a real pad lifecycle and snapshot writer over $etherpad, and the
	 * formatter trashedFile() sets the revision of. The pad lifecycle logs
	 * into a logger of its own.
	 */
	private function owed(BindingService $bindings, EtherpadClient $etherpad, bool $deleteOnTrash = true, ?LoggerInterface $logger = null, ?UserNodeResolver $nodes = null): OwedDeletions {
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('isDeleteOnTrashEnabled')->willReturn($deleteOnTrash);
		$logger ??= $this->createMock(LoggerInterface::class);
		return new OwedDeletions(
			$bindings,
			$appConfig,
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			new TrashSnapshotWriters($etherpad, $this->buildSnapshotWritingPadFileService(), $logger, new TestFaults($this->createMock(IConfig::class), $appConfig)),
			$nodes ?? $this->createMock(UserNodeResolver::class),
			$logger,
		);
	}
}
