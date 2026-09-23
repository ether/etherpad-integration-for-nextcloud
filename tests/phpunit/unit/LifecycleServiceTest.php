<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Service\SettleOutcome;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use OCP\Lock\LockedException;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\WiresALifecycleService;

class LifecycleServiceTest extends TestCase {
	use WiresALifecycleService;

	/** The revision the restored document was given, as the builder's formatter last saw it. */
	private ?int $restoredRevision = null;
	/** The snapshot revision the trash formatter reads a file at. */
	private int $trashedSnapshotRev = -1;

	/**
	 * A binding waits in pending_delete because its pad could not be deleted,
	 * and that pad may hold edits the snapshot missed. While Etherpad still
	 * has it, the file gets it back as it is.
	 */
	public function testHandleRestoreGivesTheFileBackItsOwnPadWhileItExists(): void {
		$fileId = 81;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_ACTIVE)
			->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->with('old-pad')->willReturn(7);
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->never())->method('putContent');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame('old-pad', $result['old_pad_id']);
		$this->assertSame('old-pad', $result['new_pad_id']);
	}

	/** No answer is not an answer: the pad is neither reused nor replaced. */
	public function testHandleRestoreKeepsThePadForALaterCheckWhenEtherpadCannotSay(): void {
		$fileId = 82;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_RESTORE_PENDING)
			->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willThrowException(new \RuntimeException('Connection refused'));
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->never())->method('putContent');

		// Etherpad's silence is warned about, with its cause, where it is met;
		// the restore adds a note, not a second warning for the same event.
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->once())->method('info')->with($this->stringContains('later check'), $this->anything());

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, logger: $logger)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('pad_presence_unknown', $result['reason']);
	}

	/**
	 * An access mode nobody knows must not become an unprotected pad - and
	 * must not take the file's own restore down either, so it is skipped
	 * the way every other unusable binding is.
	 */
	public function testHandleRestoreSkipsABindingWithAnUnknownAccessMode(): void {
		$fileId = 91;
		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('createGroup');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $this->createMock(BindingService::class), $etherpadClient, accessMode: 'something-else')
			->handleRestore($this->buildRestoredPadFile($fileId));

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('unknown_access_mode', $result['reason']);
	}

	/**
	 * Etherpad has already said the row's pad is gone, so a row still
	 * naming it is released when no replacement can be made: a later check
	 * could only reach the same answer, and without the row the file offers
	 * its own recovery at once.
	 */
	public function testHandleRestoreReleasesTheRowWhenNoReplacementCanBeMade(): void {
		$fileId = 87;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('deleteInState')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE)
			->willReturn(true);
		$bindingService->expects($this->never())->method('transition');
		$bindingService->expects($this->never())->method('rebind');

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->method('createPad')->willThrowException(new \RuntimeException('Connection timed out'));

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);
	}

	/**
	 * Seeding can fail after the pad is made. Nothing names it yet - the
	 * claim comes later - so it goes, and the file is left as it was.
	 */
	public function testHandleRestoreRemovesAReplacementItCouldNotSeed(): void {
		$fileId = 95;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('rebind');
		$bindingService->expects($this->once())
			->method('deleteInState')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE)
			->willReturn(true);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->method('setText')->willThrowException(new \RuntimeException('Connection reset'));
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);
	}

	/**
	 * Two restores of one file can both get as far as a replacement pad. The
	 * row decides which of them writes; the other leaves the file to the
	 * winner and takes its own pad with it.
	 */
	public function testHandleRestoreWritesNothingWhenAnotherRestoreClaimedTheRow(): void {
		$fileId = 83;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('rebind')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE, $newPadId, BindingService::STATE_ACTIVE)
			->willReturn(false);
		$bindingService->expects($this->never())->method('transition');

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->never())->method('putContent');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('binding_state_transition_conflict', $result['reason']);
	}

	/**
	 * A write that fails after the claim leaves the row naming a pad the
	 * file never learned of. That row goes, and the replacement with it.
	 */
	public function testHandleRestoreRemovesTheClaimWhenTheWriteFails(): void {
		$fileId = 88;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('rebind')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE, $newPadId, BindingService::STATE_ACTIVE)
			->willReturn(true);
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(true);
		$bindingService->expects($this->once())->method('deleteActiveBinding')->with($fileId, $newPadId)->willReturn(true);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->once())->method('putContent')->willThrowException(new \RuntimeException('disk full'));

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);
	}

	/**
	 * A claim can commit and still throw. Taken for a refusal, it would
	 * discard the very pad the row now names - so the row is asked.
	 */
	public function testHandleRestoreKeepsAClaimThatLandedWithoutSayingSo(): void {
		$fileId = 89;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('rebind')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(true);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		// Only what is left of the old pad, never the replacement.
		$etherpadClient->expects($this->once())->method('deletePad')->with('old-pad');

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * A claim that throws, on a row that then cannot be read, is a claim
	 * nobody has seen. The file is not written and the restore fails; the
	 * replacement stays, since the row may be naming it.
	 */
	public function testHandleRestoreWritesNothingOnAClaimThatCannotBeSettled(): void {
		$fileId = 92;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('rebind')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('deleteInState')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->willThrowException(new \RuntimeException('connection lost'));

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('createPad')->with('r-old-pad-abc123def456');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);
	}

	/**
	 * A claim that throws on a row that answers with another pad did not
	 * land - but that is a failure, not a lost race: the file is not
	 * written, the row still naming the old pad is released, and the
	 * replacement, known not to be named, goes.
	 */
	public function testHandleRestoreReleasesTheRowOfAClaimThatFailedWithoutLanding(): void {
		$fileId = 94;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('rebind')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(false);
		$bindingService->expects($this->once())
			->method('deleteInState')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE)
			->willReturn(true);
		$bindingService->expects($this->never())->method('transition');

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);
	}

	/**
	 * A trash can reach the file while its restore is still writing, and
	 * leave the row naming the replacement in pending_delete. That pad is
	 * the row's now, not the failed restore's to throw away.
	 */
	public function testHandleRestoreLeavesAReplacementATrashHasTakenOver(): void {
		$fileId = 93;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('rebind')->willReturn(true);
		// The trash moved the row on from active, so it is not removed.
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(true);
		$bindingService->method('deleteActiveBinding')->willReturn(false);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->once())->method('putContent')->willThrowException(new \RuntimeException('disk full'));

		// Nothing is left for the restore to release, and the log says so.
		$debug = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(static function (string $message) use (&$debug): void {
			$debug[] = $message;
		});

		try {
			$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, logger: $logger)->handleRestore($file);
			$this->fail('Expected the restore to fail.');
		} catch (LifecycleException) {
		}
		$this->assertContains('Left a binding a failed restore no longer holds.', $debug);
	}

	/**
	 * Etherpad has said the old pad is gone, but a protected pad's group can
	 * outlive it with nothing in it - and once the row names the replacement,
	 * nothing leads back to that group.
	 */
	public function testHandleRestoreTakesTheSupersededGroupWithIt(): void {
		$fileId = 84;
		$oldPadId = 'g.OLDGROUPID12345$p-old';
		$newPadId = 'g.NEWGROUPID12345$restored-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('rebind')
			->with($fileId, $oldPadId, BindingService::STATE_PENDING_DELETE, $newPadId, BindingService::STATE_ACTIVE)
			->willReturn(true);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->method('createGroup')->willReturn('g.NEWGROUPID12345');
		$etherpadClient->method('createGroupPad')->willReturn($newPadId);
		// The snapshot's formatting is what reaches the pad, not its text.
		$etherpadClient->expects($this->once())->method('setHTML')->with($newPadId, '<p>plain text</p>');
		$etherpadClient->expects($this->never())->method('setText');
		$etherpadClient->expects($this->once())->method('listPads')->with('g.OLDGROUPID12345')->willReturn([]);
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.OLDGROUPID12345');
		$etherpadClient->expects($this->never())->method('deletePad');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService(
			$fileId,
			$oldPadId,
			$bindingService,
			$etherpadClient,
			accessMode: BindingService::ACCESS_PROTECTED,
			html: '<p>plain text</p>',
			logger: $logger,
		)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($oldPadId, $result['old_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * And it stays a success when that leftover cannot be removed: the
	 * restore is already recorded, and the user's file works either way.
	 */
	public function testHandleRestoreStillSucceedsWhenTheSupersededPadSurvives(): void {
		$fileId = 85;
		$newPadId = 'r-old-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('rebind')->willReturn(true);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->once())->method('setText')->with($newPadId, 'plain text');
		$etherpadClient->method('deletePad')->willReturnCallback(static function (string $padId): void {
			if ($padId === 'old-pad') {
				throw new \RuntimeException('still down');
			}
		});

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);
		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
	}

	/**
	 * A pad under the row's id with fewer revisions than the file's
	 * snapshot is not the pad the file knew: created again since, or back
	 * from an older backup. Taken back, it would show the file empty or old
	 * and let a sync write that over the snapshot. The file gets a pad made
	 * from its snapshot, and the other is left to whoever wrote into it.
	 */
	public function testHandleRestoreReplacesAPadThatIsBehindTheSnapshot(): void {
		$fileId = 96;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('transition');
		$bindingService->expects($this->once())
			->method('rebind')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE, $newPadId, BindingService::STATE_ACTIVE)
			->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(
			static fn (string $padId): int => $padId === 'old-pad' ? 0 : 1,
		);
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->never())->method('deletePad');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('fewer revisions'), $this->anything());

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, logger: $logger, snapshotRev: 500)
			->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($newPadId, $result['new_pad_id']);
		// The new pad's own count, so the file is in sync with it at once.
		$this->assertSame(1, $this->restoredRevision);
	}

	/**
	 * Without the file there is no revision to hold the pad to, so the row
	 * waits for a later check rather than being settled blind.
	 */
	public function testHandleRestoreKeepsTheRowWaitingWhileTheFileCannotBeRead(): void {
		$fileId = 97;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_RESTORE_PENDING)
			->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('getRevisionsCount');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willThrowException(new \RuntimeException('locked'));

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('file_unreadable', $result['reason']);
	}

	/**
	 * A deletion owed is settled on restore even once deleting on trash is
	 * switched off: the file is back, and a row left in pending_delete
	 * would keep it from opening for good.
	 */
	public function testHandleRestoreSettlesAnOwedDeletionWithTheSettingOff(): void {
		$fileId = 98;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_ACTIVE)
			->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, deleteOnTrash: false)
			->handleRestore($this->buildRestoredPadFile($fileId));

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
	}

	/** The sweep settles a restore that had to wait, by the same rules. */
	public function testSettleWaitingFileTakesThePadBackOnceEtherpadAnswers(): void {
		$fileId = 99;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with($fileId, 'old-pad', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_ACTIVE)
			->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('getRevisionsCount')->with('old-pad', 5)->willReturn(3);

		$outcome = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, state: BindingService::STATE_RESTORE_PENDING)
			->settleWaitingFile($this->buildRestoredPadFile($fileId), new RunBudget(new FixedClock(), 5.0));

		$this->assertSame(SettleOutcome::Settled, $outcome);
	}

	/**
	 * The sweep reads an outage from this, so only Etherpad's silence counts
	 * as one. A file it cannot read says nothing about Etherpad, and five of
	 * them at the front of the queue would otherwise end every run there.
	 * Either way the row moves to the back.
	 */
	public function testSettleWaitingFileCountsOnlyEtherpadsSilenceAsAnOutage(): void {
		$outcomes = [];
		foreach (['no answer', 'unreadable'] as $case) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->expects($this->once())
				->method('transition')
				->with(105, 'old-pad', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_RESTORE_PENDING)
				->willReturn(true);
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willThrowException(new \RuntimeException('Connection refused'));
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn(105);
			$file->method('getName')->willReturn('Restored.pad');
			if ($case === 'unreadable') {
				$file->method('getContent')->willThrowException(new \RuntimeException('locked'));
			} else {
				$file->method('getContent')->willReturn('doc-before');
			}
			$outcomes[$case] = $this->buildPendingDeleteRestoreService(105, 'old-pad', $bindingService, $etherpadClient, state: BindingService::STATE_RESTORE_PENDING)
				->settleWaitingFile($file, new RunBudget(new FixedClock(), 5.0));
		}

		$this->assertSame(['no answer' => SettleOutcome::Unanswered, 'unreadable' => SettleOutcome::Left], $outcomes);
	}

	/**
	 * A sweep writes no file. A pad Etherpad has given up on, or one behind
	 * the snapshot, releases the row instead, and the file makes its own pad
	 * when it is next opened - by someone who can write to it.
	 */
	public function testSettleWaitingFileReleasesARowWhosePadIsNotTheFilesAnyMore(): void {
		foreach (['gone' => 'padID does not exist', 'behind' => 0] as $case => $answer) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->expects($this->once())
				->method('deleteInState')
				->with(106, 'old-pad', BindingService::STATE_RESTORE_PENDING)
				->willReturn(true);
			$bindingService->expects($this->never())->method('rebind');
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function () use ($answer): int {
				return is_int($answer) ? $answer : throw new \RuntimeException($answer);
			});
			$etherpadClient->expects($this->never())->method('createPad');
			$etherpadClient->expects($this->never())->method('deletePad');
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($case === 'behind' ? $this->once() : $this->never())
				->method('warning')
				->with($this->stringContains('fewer revisions'), $this->callback(static fn (array $context): bool => ($context['padId'] ?? '') === 'old-pad'));
			// An admin asking why the file suddenly wants recovering finds this.
			$logger->expects($this->once())
				->method('info')
				->with($this->stringContains('Released the binding'), $this->callback(
					static fn (array $context): bool => ($context['fileId'] ?? 0) === 106 && ($context['padId'] ?? '') === 'old-pad'
				));
			$file = $this->buildRestoredPadFile(106);
			$file->expects($this->never())->method('putContent');

			$outcome = $this->buildPendingDeleteRestoreService(106, 'old-pad', $bindingService, $etherpadClient, logger: $logger, snapshotRev: 7, state: BindingService::STATE_RESTORE_PENDING)
				->settleWaitingFile($file, new RunBudget(new FixedClock(), 5.0));

			$this->assertSame(SettleOutcome::Settled, $outcome, $case);
		}
	}

	/**
	 * Trashing a file whose restore is undecided hands the row back as a
	 * deletion owed even with deleting on trash switched off: nothing is
	 * deleted by it, and a row left waiting would keep the file from
	 * opening once it is back.
	 */
	public function testHandleTrashHandsAnUndecidedRestoreBackWithTheSettingOff(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with(107, 'old-pad', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_PENDING_DELETE)
			->willReturn(true);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method($this->anything());

		$result = $this->buildPendingDeleteRestoreService(107, 'old-pad', $bindingService, $etherpadClient, state: BindingService::STATE_RESTORE_PENDING, deleteOnTrash: false)
			->handleTrash($this->buildRestoredPadFile(107));

		$this->assertSame(LifecycleService::RESULT_TRASHED, $result['status']);
		$this->assertTrue($result['delete_pending']);
	}

	/** And a restore settles a row that still waits undecided, with the setting off as well. */
	public function testHandleRestoreSettlesAnUndecidedRestoreWithTheSettingOff(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with(108, 'old-pad', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_ACTIVE)
			->willReturn(true);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);

		$result = $this->buildPendingDeleteRestoreService(108, 'old-pad', $bindingService, $etherpadClient, state: BindingService::STATE_RESTORE_PENDING, deleteOnTrash: false)
			->handleRestore($this->buildRestoredPadFile(108));

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
	}

	/**
	 * A sweep can settle an undecided row while the same file goes to the
	 * trash. The trash then finds the row active and trashes the file as
	 * what it is now, rather than leaving an active row behind a file in
	 * the trash, with its pad orphaned once the trash is emptied.
	 */
	public function testHandleTrashTrashesARowASweepSettledMeanwhile(): void {
		$rows = [
			['file_id' => 109, 'pad_id' => 'old-pad', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_RESTORE_PENDING],
			['file_id' => 109, 'pad_id' => 'old-pad', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE],
		];
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturnCallback(static function () use (&$rows): array {
			return count($rows) > 1 ? array_shift($rows) : $rows[0];
		});
		$bindingService->method('transition')->willReturn(false);
		$bindingService->expects($this->once())->method('deleteByFileId')->with(109);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('deletePad')->with('old-pad');

		$service = $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $this->buildSnapshotWritingPadFileService(),
		);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(109);
		$file->method('getName')->willReturn('Raced.pad');
		$file->method('getContent')->willReturn('doc-before');

		$result = $service->handleTrash($file);

		$this->assertSame(LifecycleService::RESULT_TRASHED, $result['status']);
		$this->assertFalse($result['delete_pending']);
	}

	/**
	 * A database that fails on the way reaches the caller as the restore's
	 * failure, like every other one - not as a driver exception carrying
	 * its statement.
	 */
	public function testHandleRestoreReportsABindingFailureAsItsOwn(): void {
		$fileId = 100;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('transition')->willThrowException(new \RuntimeException('connection lost'));

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->handleRestore($this->buildRestoredPadFile($fileId));
	}

	/** And so does a trash of a file whose restore is still undecided. */
	public function testHandleTrashReportsABindingFailureOnAnUndecidedRestoreAsItsOwn(): void {
		$fileId = 101;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('transition')->willThrowException(new \RuntimeException('connection lost'));

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $this->createMock(EtherpadClient::class), state: BindingService::STATE_RESTORE_PENDING)
			->handleTrash($this->buildRestoredPadFile($fileId));
	}

	/**
	 * Each replacement is named after the pad before it. Built on an earlier
	 * restore's name it grew by fifteen characters each time, and Etherpad
	 * refuses a pad name past fifty: the second replacement of one file
	 * already failed.
	 */
	public function testAReplacementNameDoesNotGrowAndFitsEtherpad(): void {
		$created = [];
		foreach (['r-nc-abcdefghijklmnopqrstuvwx-zyxwvutsrqpo', 'legacy-' . str_repeat('x', 80)] as $oldPadId) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->method('rebind')->willReturn(true);
			$etherpadClient = $this->buildEtherpadWithoutThePad();
			$etherpadClient->method('createPad')->willReturnCallback(static function (string $padId) use (&$created): void {
				$created[] = $padId;
			});
			$this->buildPendingDeleteRestoreService(104, $oldPadId, $bindingService, $etherpadClient)->handleRestore($this->buildRestoredPadFile(104));
		}

		$this->assertSame('r-nc-abcdefghijklmnopqrstuvwx-abc123def456', $created[0]);
		$this->assertSame('r-legacy-' . str_repeat('x', 28) . '-abc123def456', $created[1]);
		$this->assertSame(50, strlen($created[1]));
	}

	/**
	 * A pad is deleted on trash only once its content is in the file. A
	 * delete through WebDAV holds the file's lock, and the snapshot can fail
	 * in other ways too, each a TrashSnapshotMiss (TrashSnapshotWriterTest);
	 * whether the file could not be read or not be written, the pad stays as
	 * it is and its deletion is owed - a restore takes it back, and the
	 * sweep takes the snapshot once the file sits in the trash.
	 */
	public function testHandleTrashKeepsThePadWhenNoFreshSnapshotCanBeWritten(): void {
		foreach (['file locked by its delete', 'write refused'] as $case) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->method('findByFileId')->willReturn([
				'file_id' => 110,
				'pad_id' => 'pad-kept',
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_ACTIVE,
			]);
			$bindingService->expects($this->once())
				->method('transition')
				->with(110, 'pad-kept', BindingService::STATE_ACTIVE, BindingService::STATE_PENDING_DELETE)
				->willReturn(true);
			$bindingService->expects($this->never())->method('deleteByFileId');

			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->expects($this->never())->method('deletePad');
			$etherpadClient->method('getRevisionsCount')->willReturn(3);

			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn(110);
			$file->method('getName')->willReturn('Kept.pad');
			if ($case === 'file locked by its delete') {
				$file->method('getContent')->willThrowException(new LockedException('Kept.pad'));
			} else {
				$file->method('getContent')->willReturn('doc-before');
				$file->method('putContent')->willThrowException(new \RuntimeException('disk full'));
			}

			$result = $this->buildTrashService($bindingService, $etherpadClient)->handleTrash($file);

			$this->assertSame(LifecycleService::RESULT_TRASHED, $result['status'], $case);
			$this->assertTrue($result['delete_pending'], $case);
			$this->assertFalse($result['snapshot_persisted'], $case);
		}
	}

	/**
	 * A file that already holds the pad's revision needs no new snapshot:
	 * the pad goes without a fetch or a write, at trash time as much as in
	 * the sweep.
	 */
	public function testNoSnapshotIsTakenThatTheFileHasAlready(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn([
			'file_id' => 110,
			'pad_id' => 'pad-current',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_ACTIVE,
		]);
		$bindingService->expects($this->once())->method('deleteByFileId')->with(110);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('getRevisionsCount')->willReturn(4);
		$etherpadClient->expects($this->never())->method('getText');
		$etherpadClient->expects($this->once())->method('deletePad')->with('pad-current');
		// Still in Files at trash time, under its own name.
		$this->trashedSnapshotRev = 4;
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(110);
		$file->method('getName')->willReturn('Current.pad');
		$file->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildTrashService($bindingService, $etherpadClient)->handleTrash($file);

		$this->assertTrue($result['snapshot_persisted']);
		$this->assertFalse($result['delete_pending']);

		$bindingService = $this->pendingTrashRow(111, 'pad-current');
		$bindingService->expects($this->once())->method('deleteInState')->willReturn(true);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('getRevisionsCount')->willReturn(4);
		$etherpadClient->expects($this->never())->method('getText');
		$etherpadClient->expects($this->once())->method('deletePad');
		$file = $this->trashedFile(111, snapshotRev: 4);
		$file->expects($this->never())->method('putContent');

		$this->assertSame(SettleOutcome::Settled, $this->buildTrashService($bindingService, $etherpadClient)->finishTrash($file, new RunBudget(new FixedClock(), 20.0)));
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

		$outcome = $this->buildTrashService($bindingService, $etherpadClient)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

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

		$outcome = $this->buildTrashService($bindingService, $etherpadClient)->finishTrash($this->trashedFile(111), new RunBudget(new FixedClock(), 20.0));

		$this->assertSame(SettleOutcome::Left, $outcome);
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

		$outcome = $this->buildTrashService($bindingService, $etherpadClient, logger: $logger)->finishTrash($this->trashedFile(111), new RunBudget(new FixedClock(), 20.0));

		$this->assertSame(SettleOutcome::Settled, $outcome);
	}

	/** No row is taken when no call would fit in the run any more: both wait for the next one. */
	public function testNoRowIsTakenWithoutTimeToDeleteThePad(): void {
		$clock = new FixedClock();
		$bindingService = $this->pendingTrashRow(111, 'pad-late');
		$bindingService->expects($this->never())->method('deleteInState');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function () use ($clock): int {
			$clock->advance(19);
			return 4;
		});
		$etherpadClient->expects($this->never())->method('deletePad');

		$outcome = $this->buildTrashService($bindingService, $etherpadClient)->finishTrash($this->trashedFile(111, snapshotRev: 4), new RunBudget($clock, 20.0));

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
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function () use ($clock): int {
			$clock->advance(17);
			return 4;
		});
		$etherpadClient->method('listPads')->willReturnCallback(static function (string $groupId, ?int $timeout) use ($clock, $padId, &$calls): array {
			$calls['listPads'] = $timeout;
			$clock->advance(5);
			return [$padId];
		});
		$etherpadClient->expects($this->once())->method('deleteGroup')->willReturnCallback(static function (string $groupId, ?int $timeout) use (&$calls): void {
			$calls['deleteGroup'] = $timeout;
		});

		$outcome = $this->buildTrashService($bindingService, $etherpadClient)->finishTrash($this->trashedFile(117, snapshotRev: 4), new RunBudget($clock, 20.0));

		$this->assertSame(SettleOutcome::Settled, $outcome);
		$this->assertSame(['listPads' => null, 'deleteGroup' => null], $calls);
	}

	/**
	 * A restore can take the file back while its snapshot is fetched. The
	 * node read before still names the path in the trash, and a write
	 * through it would make a new file there that nothing points to. The
	 * file is looked up again first, and nothing is written or deleted.
	 */
	public function testAFileRestoredWhileItsSnapshotWasTakenIsNotWrittenTo(): void {
		$bindingService = $this->pendingTrashRow(118, 'pad-restored');
		$bindingService->expects($this->never())->method('deleteInState');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(5);
		$etherpadClient->expects($this->never())->method('deletePad');
		$file = $this->trashedFile(118);
		$file->expects($this->never())->method('putContent');
		$nodes = $this->createMock(UserNodeResolver::class);
		$nodes->expects($this->once())->method('hasMoved')->with($file)->willReturn(true);

		$outcome = $this->buildTrashService($bindingService, $etherpadClient, nodes: $nodes)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

		$this->assertSame(SettleOutcome::Left, $outcome);
	}

	/**
	 * Held to the file's snapshot revision like a restore. A pad behind it
	 * is not the file's and a pad that is gone has nothing to give: the row
	 * is released, the file keeps the snapshot it has, and a pad someone may
	 * have written into is not touched. No answer leaves everything; neither
	 * does a pad that changed while it was read, until the next run.
	 */
	public function testFinishTrashGoesByWhatEtherpadSays(): void {
		$cases = [
			'behind' => [[2], SettleOutcome::Settled, true, false],
			// A public pad Etherpad has just called absent leaves nothing to delete.
			'gone' => ['padID does not exist', SettleOutcome::Settled, true, false],
			'no answer' => ['Connection refused', SettleOutcome::Unanswered, false, false],
			// The count the presence was read from, and the one after the text.
			'changed while read' => [[5, 6], SettleOutcome::Left, false, false],
		];
		foreach ($cases as $case => [$answer, $expected, $released, $deleteTried]) {
			$bindingService = $this->pendingTrashRow(112, 'pad-trashed');
			$bindingService->expects($released ? $this->once() : $this->never())->method('deleteInState')->willReturn(true);
			$etherpadClient = $this->createMock(EtherpadClient::class);
			if (is_array($answer)) {
				$etherpadClient->method('getRevisionsCount')->willReturnOnConsecutiveCalls(...$answer);
			} else {
				$etherpadClient->method('getRevisionsCount')->willThrowException(new \RuntimeException($answer));
			}
			$etherpadClient->expects($deleteTried ? $this->once() : $this->never())->method('deletePad')
				->willThrowException(new \RuntimeException('padID does not exist'));
			$file = $this->trashedFile(112, snapshotRev: 3);
			$file->expects($this->never())->method('putContent');

			$outcome = $this->buildTrashService($bindingService, $etherpadClient)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

			$this->assertSame($expected, $outcome, $case);
		}
	}

	/** A row that is not a deletion owed any more, or a file that cannot be read, is left for later. */
	public function testFinishTrashLeavesWhatItCannotSettle(): void {
		$active = $this->createMock(BindingService::class);
		$active->method('findByFileId')->willReturn(['file_id' => 113, 'pad_id' => 'pad-a', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE]);
		$active->expects($this->never())->method('deleteInState');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('getRevisionsCount');

		$this->assertSame(SettleOutcome::Left, $this->buildTrashService($active, $etherpadClient)->finishTrash($this->trashedFile(113), new RunBudget(new FixedClock(), 20.0)));

		$pending = $this->pendingTrashRow(114, 'pad-b');
		$pending->expects($this->never())->method('deleteInState');
		$locked = $this->createMock(File::class);
		$locked->method('getId')->willReturn(114);
		$locked->method('getContent')->willThrowException(new LockedException('Locked.pad'));

		$this->assertSame(SettleOutcome::Left, $this->buildTrashService($pending, $etherpadClient)->finishTrash($locked, new RunBudget(new FixedClock(), 20.0)));

		// Switched off after the trash: the pad stays, as the setting says now.
		$switchedOff = $this->pendingTrashRow(115, 'pad-c');
		$switchedOff->expects($this->never())->method('deleteInState');
		$etherpadClient->expects($this->never())->method('deletePad');
		$file = $this->trashedFile(115);
		$file->expects($this->never())->method('putContent');

		$this->assertSame(SettleOutcome::Left, $this->buildTrashService($switchedOff, $etherpadClient, deleteOnTrash: false)->finishTrash($file, new RunBudget(new FixedClock(), 20.0)));
	}

	/**
	 * A trashed file the sweep cannot use is tried again each run, from the
	 * back of the queue, but reported at warning level only the first time:
	 * after that its row's updated_at has moved past its deleted_at.
	 */
	public function testATrashedFileThatCannotBeReadIsReportedOnce(): void {
		$cases = [
			'first time' => [100, 100, 'warning'],
			'reported before' => [100, 400, 'debug'],
			// Nothing to tell a repeat by: each run the admin page makes reports it.
			'no deleted_at' => [null, 400, 'warning'],
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

			$outcome = $this->buildTrashService($bindingService, $this->createMock(EtherpadClient::class), logger: $logger)
				->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

			$this->assertSame(SettleOutcome::Left, $outcome, $case);
		}
	}

	/**
	 * What passes by itself - a lock, a pad that changed while it was read,
	 * a file a restore moved - is tried again where the row is: moving it
	 * back would count it as reported, and the file's own trouble met
	 * later would go to debug. An empty file waits for its trash, and moves
	 * back like the file's trouble.
	 */
	public function testOnlyTheFilesOwnTroubleMovesTheRowBack(): void {
		foreach (['file locked' => false, 'pad changed while read' => false, 'file moved' => false, 'file empty' => true] as $case => $movesBack) {
			$bindingService = $this->pendingTrashRow(119, 'pad-e');
			$bindingService->expects($movesBack ? $this->once() : $this->never())->method('transition');
			$bindingService->expects($this->never())->method('deleteInState');
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturnOnConsecutiveCalls(5, $case === 'pad changed while read' ? 6 : 5);
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn(119);
			match ($case) {
				'file locked' => $file->method('getContent')->willThrowException(new LockedException('Locked.pad')),
				'file empty' => $file->method('getContent')->willReturn(''),
				default => $file->method('getContent')->willReturn('doc-before'),
			};
			$nodes = $this->createMock(UserNodeResolver::class);
			$nodes->method('hasMoved')->willReturn($case === 'file moved');

			$outcome = $this->buildTrashService($bindingService, $etherpadClient, nodes: $nodes)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

			$this->assertSame(SettleOutcome::Left, $outcome, $case);
		}
	}

	/**
	 * Etherpad giving no answer while the snapshot is read is the same
	 * silence as no answer to the question before it: it counts towards the
	 * run's stop, and the row stays where it is. Only a file's own trouble
	 * moves a row back.
	 */
	public function testEtherpadSilenceWhileTheSnapshotIsReadIsNoAnswer(): void {
		$bindingService = $this->pendingTrashRow(120, 'pad-f');
		$bindingService->expects($this->never())->method('transition');
		$bindingService->expects($this->never())->method('deleteInState');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(5);
		$etherpadClient->method('getText')->willThrowException(new EtherpadClientException('Operation timed out'));
		$etherpadClient->expects($this->never())->method('deletePad');
		$file = $this->trashedFile(120);
		$file->expects($this->never())->method('putContent');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'A trashed .pad file did not get its snapshot. Its pad is kept for now.',
			$this->callback(static fn (array $context): bool => $context['reason'] === 'snapshot_not_fetched'),
		);

		$outcome = $this->buildTrashService($bindingService, $etherpadClient, logger: $logger)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

		$this->assertSame(SettleOutcome::Unanswered, $outcome);
	}

	/**
	 * The pad is counted once more after its snapshot is written: an edit
	 * that came while the file was written is not in it, so the pad stays
	 * and the next run writes another snapshot. Etherpad giving no answer
	 * to that count is its silence like any other.
	 */
	public function testAPadThatMovedOnAfterItsSnapshotWasWrittenStays(): void {
		foreach (['moved on' => [SettleOutcome::Left, 6], 'no answer' => [SettleOutcome::Unanswered, null]] as $case => [$expected, $recount]) {
			$bindingService = $this->pendingTrashRow(122, 'pad-g');
			$bindingService->expects($this->never())->method('deleteInState');
			$bindingService->expects($this->never())->method('transition');
			$calls = 0;
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function () use (&$calls, $recount): int {
				// The probe, the count after the text, then the one after the write.
				return ++$calls < 3 ? 5 : ($recount ?? throw new EtherpadClientException('Connection reset'));
			});
			$etherpadClient->expects($this->never())->method('deletePad');
			$file = $this->trashedFile(122);
			$file->expects($this->once())->method('putContent');

			$outcome = $this->buildTrashService($bindingService, $etherpadClient)->finishTrash($file, new RunBudget(new FixedClock(), 20.0));

			$this->assertSame($expected, $outcome, $case);
		}
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
		$this->assertSame(SettleOutcome::Left, $this->buildTrashService($trashRow, $etherpadClient)->finishTrash($slowRead(123), new RunBudget($clock, 20.0)));

		// A fresh run on the same clock: the read takes its time as well.
		$restoreRow = $this->createMock(BindingService::class);
		$restoreRow->expects($this->never())->method('transition');
		$this->assertSame(
			SettleOutcome::Left,
			$this->buildPendingDeleteRestoreService(124, 'old-pad', $restoreRow, $etherpadClient, state: BindingService::STATE_RESTORE_PENDING)
				->settleWaitingFile($slowRead(124), new RunBudget($clock, 20.0)),
		);
	}

	/**
	 * A restore that asked about the pad and then lost the row to the sweep
	 * finishing the trash finds no row when it reads again. The sweep wrote
	 * the pad's content into the file before it took the row, so the restore
	 * makes a new pad from the file, as one without a binding does, rather
	 * than leave the file with no pad. A sweep in its place writes nothing.
	 */
	public function testARestoreThatLostItsRowToTheSweepMakesANewPadFromTheFile(): void {
		$row = ['file_id' => 125, 'pad_id' => 'old-pad', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE];
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturnOnConsecutiveCalls($row, null);
		$bindingService->expects($this->once())->method('transition')->willReturn(false);
		$bindingService->expects($this->once())->method('createBinding')->with(125, 'r-old-pad-abc123def456', BindingService::ACCESS_PUBLIC);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);
		$etherpadClient->expects($this->once())->method('createPad')->with('r-old-pad-abc123def456');
		$file = $this->buildRestoredPadFile(125);
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService(125, 'old-pad', $bindingService, $etherpadClient)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame('r-old-pad-abc123def456', $result['new_pad_id']);

		$sweepRow = $this->createMock(BindingService::class);
		// Gone on the second read here too: the sweep still writes nothing.
		$sweepRow->method('findByFileId')->willReturnOnConsecutiveCalls($row, null);
		$sweepRow->method('transition')->willReturn(false);
		$sweepRow->expects($this->never())->method('createBinding');
		$sweeper = $this->createMock(EtherpadClient::class);
		$sweeper->method('getRevisionsCount')->willReturn(3);
		$sweeper->expects($this->never())->method('createPad');
		$file = $this->buildRestoredPadFile(125);
		$file->expects($this->never())->method('putContent');

		$this->assertSame(
			SettleOutcome::Left,
			$this->buildPendingDeleteRestoreService(125, 'old-pad', $sweepRow, $sweeper)->settleWaitingFile($file, new RunBudget(new FixedClock(), 20.0)),
		);
	}

	/**
	 * A file gone for good takes its pad first, then its row: no restore
	 * can come for it, and a pad that could not be deleted keeps its row,
	 * so the next run tries again. What Etherpad says decides the rest; with
	 * deleting on trash switched off nothing is asked or taken at all. A
	 * public pad Etherpad has just called absent takes no further call; a
	 * group pad does, for its empty group.
	 */
	public function testAGoneFilesDeletionGoesByEtherpadsAnswerAndTheSetting(): void {
		$groupPad = 'g.ABCDEFGHIJKLMNOP$p-gone';
		$cases = [
			'there' => ['pad-gone', 3, true, SettleOutcome::Settled, ['pad', 'row']],
			'gone' => ['pad-gone', 'padID does not exist', true, SettleOutcome::Settled, ['row']],
			'gone, a group pad' => [$groupPad, 'padID does not exist', true, SettleOutcome::Settled, ['group', 'row']],
			'no answer' => ['pad-gone', 'Connection refused', true, SettleOutcome::Unanswered, []],
			'delete refused' => ['pad-gone', 3, true, SettleOutcome::Unanswered, ['pad']],
			'setting off' => ['pad-gone', 3, false, SettleOutcome::Left, []],
		];
		foreach ($cases as $case => [$padId, $answer, $enabled, $expected, $steps]) {
			$order = [];
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->method('deleteInState')->willReturnCallback(static function (int $fileId, string $padId, string $state) use (&$order): bool {
				$order[] = 'row';
				return true;
			});
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->expects($case === 'setting off' ? $this->never() : $this->once())
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

			$outcome = $this->buildTrashService($bindingService, $etherpadClient, deleteOnTrash: $enabled)
				->finishGoneFile(120, $padId, BindingService::STATE_PENDING_DELETE, new RunBudget(new FixedClock(), 20.0));

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

		$this->buildTrashService($bindingService, $etherpadClient)
			->finishGoneFile(121, 'g.ABCDEFGHIJKLMNOP$p-gone', BindingService::STATE_PENDING_DELETE, new RunBudget(new FixedClock(), 20.0));

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
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function () use ($clock): int {
			$clock->advance(19);
			return 3;
		});
		$etherpadClient->expects($this->never())->method('listPads');
		$etherpadClient->expects($this->never())->method('deleteGroup');
		$etherpadClient->expects($this->never())->method('deletePad');

		$outcome = $this->buildTrashService($bindingService, $etherpadClient)
			->finishGoneFile(122, 'g.ABCDEFGHIJKLMNOP$p-gone', BindingService::STATE_PENDING_DELETE, new RunBudget($clock, 20.0));

		$this->assertSame(SettleOutcome::Left, $outcome);
	}

	/** Owed since $deletedAt; $updatedAt past that once a run moved it back. */
	private function pendingTrashRow(int $fileId, string $padId, int $updatedAt = 100, ?int $deletedAt = 100): BindingService&MockObject {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'file_id' => $fileId,
			'pad_id' => $padId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_PENDING_DELETE,
			'deleted_at' => $deletedAt,
			'updated_at' => $updatedAt,
		]);
		return $bindingService;
	}

	/** A .pad in its owner's trash, holding 'doc-before'; the formatter reads it at $snapshotRev. */
	private function trashedFile(int $fileId, int $snapshotRev = -1): File&MockObject {
		$this->trashedSnapshotRev = $snapshotRev;
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Trashed.pad.d100');
		$file->method('getContent')->willReturn('doc-before');
		return $file;
	}

	private function buildTrashService(BindingService $bindingService, EtherpadClient $etherpadClient, bool $deleteOnTrash = true, ?LoggerInterface $logger = null, ?UserNodeResolver $nodes = null): LifecycleService {
		return $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $this->buildSnapshotWritingPadFileService(),
			deleteOnTrash: $deleteOnTrash,
			logger: $logger,
			nodes: $nodes,
		);
	}

	/** A formatter that reads 'doc-before' and writes a fresh snapshot into it as 'doc-after'. */
	private function buildSnapshotWritingPadFileService(): PadFileService&MockObject {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturnCallback(fn (): ParsedPadFile => new ParsedPadFile(
			frontmatter: [],
			body: 'body',
			padId: 'pad',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: $this->trashedSnapshotRev,
		));
		$padFileService->method('withExportSnapshot')->willReturn('doc-after');
		return $padFileService;
	}

	/** Etherpad answers for the old pad that it does not exist. */
	private function buildEtherpadWithoutThePad(): EtherpadClient&MockObject {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willThrowException(new \RuntimeException('padID does not exist'));
		$etherpadClient->method('buildPadUrl')->willReturnCallback(static fn (string $padId): string => 'https://pad.example.test/p/' . rawurlencode($padId));
		return $etherpadClient;
	}

	/** A .pad file back from the trash, holding 'doc-before'. */
	private function buildRestoredPadFile(int $fileId): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willReturn('doc-before');
		return $file;
	}

	/** A restore of a file whose row waits in pending_delete, naming $oldPadId. Restored, the file reads 'doc-after'. */
	private function buildPendingDeleteRestoreService(
		int $fileId,
		string $oldPadId,
		BindingService&MockObject $bindingService,
		EtherpadClient $etherpadClient,
		string $accessMode = BindingService::ACCESS_PUBLIC,
		string $html = '',
		?LoggerInterface $logger = null,
		int $snapshotRev = -1,
		string $state = BindingService::STATE_PENDING_DELETE,
		bool $deleteOnTrash = true,
	): LifecycleService {
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'file_id' => $fileId,
			'pad_id' => $oldPadId,
			'access_mode' => $accessMode,
			'state' => $state,
		]);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [],
			body: 'body',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: $snapshotRev,
		);
		$padFileService->method('readPad')->with('doc-before')->willReturn($parsedPad);
		$padFileService->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn(['text' => 'plain text', 'html' => $html]);
		$padFileService->method('withRestoredSnapshot')->willReturnCallback(
			function (ParsedPadFile $pad, string $text, string $html, string $padId, string $padUrl, int $revision = -1): string {
				$this->restoredRevision = $revision;
				return 'doc-after';
			},
		);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abc123def456');

		return $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			deleteOnTrash: $deleteOnTrash,
			logger: $logger,
			secureRandom: $secureRandom,
		);
	}

	public function testHandleTrashSkipsNonPadFiles(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(12);
		$file->method('getName')->willReturn('Notes.txt');

		$service = $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			logger: $logger,
			secureRandom: $secureRandom,
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('not_pad_file', $result['reason']);
		$this->assertSame(12, $result['file_id']);
	}

	public function testHandleTrashMarksPendingDeleteWhenEtherpadDeleteFails(): void {
		$fileId = 21;
		$padId = 'pad-abc';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn([
				'file_id' => $fileId,
				'pad_id' => $padId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_ACTIVE,
			]);
		$bindingService->expects($this->once())
			->method('transition')
			->with($fileId, $padId, BindingService::STATE_ACTIVE, BindingService::STATE_PENDING_DELETE)
			->willReturn(true);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->never())->method('parsePadFile');
		$padFileService->expects($this->never())->method('isExternalFrontmatter');
		$parsedPad = new ParsedPadFile(
			frontmatter: [],
			body: '',
			padId: $padId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		);
		$padFileService->method('readPad')->with('doc-current')->willReturn($parsedPad);
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with($this->identicalTo($parsedPad), new PadSnapshot('snapshot-text', '<p>snapshot-html</p>', 7))
			->willReturn('doc-trash-updated');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('getText')->with($padId)->willReturn('snapshot-text');
		$etherpadClient->expects($this->once())->method('getHTML')->with($padId)->willReturn('<p>snapshot-html</p>');
		// Before and after the text: the snapshot is kept only when they agree.
		// Before the text, after it, and once more after the write.
		$etherpadClient->expects($this->exactly(3))->method('getRevisionsCount')->with($padId)->willReturn(7);
		$etherpadClient->expects($this->once())
			->method('deletePad')
			->with($padId)
			->willThrowException(new \RuntimeException('temporary failure'));

		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Pad.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-current');
		$file->expects($this->once())->method('putContent')->with('doc-trash-updated');

		$service = $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			logger: $logger,
			secureRandom: $secureRandom,
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_TRASHED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($padId, $result['pad_id']);
		$this->assertTrue($result['snapshot_persisted']);
		$this->assertTrue($result['delete_pending']);
	}

	public function testHandleTrashReturnsSkippedOnStateTransitionConflict(): void {
		$fileId = 55;
		$padId = 'pad-race';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn([
				'file_id' => $fileId,
				'pad_id' => $padId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_ACTIVE,
			]);
		// Another flow moved the row first.
		$bindingService->expects($this->once())
			->method('transition')
			->willReturn(false);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->never())->method('withExportSnapshot');

		// No snapshot, so the pad is kept: marking its deletion owed is what
		// loses the race.
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('deletePad');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Pad.pad');
		$file->expects($this->once())->method('getContent')->willReturn('');

		$service = $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			logger: $logger,
			secureRandom: $secureRandom,
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('binding_state_transition_conflict', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($padId, $result['pad_id']);
	}

	/**
	 * Trashed again while its restore is still undecided. The pad may hold
	 * the only current copy, so Etherpad is not touched; the row is owed a
	 * deletion again, as it was before the restore.
	 */
	public function testHandleTrashLeavesAnUndecidedPadAlone(): void {
		$fileId = 56;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'file_id' => $fileId,
			'pad_id' => 'old-pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_RESTORE_PENDING,
		]);
		$bindingService->expects($this->once())
			->method('transition')
			->with($fileId, 'old-pad', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_PENDING_DELETE)
			->willReturn(true);
		$bindingService->expects($this->never())->method('deleteByFileId');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method($this->anything());

		$file = $this->buildRestoredPadFile($fileId);
		$file->expects($this->never())->method('putContent');

		$service = $this->lifecycleService(bindings: $bindingService, etherpad: $etherpadClient);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_TRASHED, $result['status']);
		$this->assertTrue($result['delete_pending']);
	}

	public function testHandleRestoreWithoutBindingRecreatesManagedPublicPad(): void {
		$fileId = 91;
		$oldPadId = 'old-public-pad';
		$newPadId = 'r-old-public-pad-abc123def456';
		$newPadUrl = 'https://pad.example.test/p/' . rawurlencode($newPadId);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with($fileId, $newPadId, BindingService::ACCESS_PUBLIC);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [
				'pad_id' => $oldPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => 'trashed',
			],
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/' . rawurlencode($oldPadId),
			isExternal: false,
			snapshotRev: -1,
		);
		$padFileService->method('readPad')->with('doc-before')->willReturn($parsedPad);
		$padFileService->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn([
			'text' => 'plain text',
			'html' => '',
		]);
		$padFileService->expects($this->once())
			->method('withRestoredSnapshot')
			->with($this->identicalTo($parsedPad), 'plain text', '', $newPadId, $newPadUrl)
			->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->once())->method('setText')->with($newPadId, 'plain text');
		$etherpadClient->expects($this->once())->method('buildPadUrl')->with($newPadId)->willReturn($newPadUrl);
		$etherpadClient->expects($this->never())->method('deletePad');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->expects($this->once())
			->method('generate')
			->with(12, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abc123def456');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			secureRandom: $secureRandom,
		)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['old_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * `createBinding` can commit and still throw, and the write that would
	 * have made the file agree with the row never runs. A flag says no row
	 * while a row is there naming the pad about to be deleted — which is
	 * how a binding ends up pointing at a pad that is gone.
	 */
	public function testHandleRestoreWithoutBindingRemovesARowItsFailedWriteLeftBehind(): void {
		$fileId = 92;
		$oldPadId = 'old-public-pad';
		$newPadId = 'r-old-public-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(true);
		$bindingService->expects($this->once())->method('deleteActiveBinding')->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . $newPadId);
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildNoBindingRestoreService($bindingService, $etherpadClient, $oldPadId, $newPadId)->handleRestore($file);
	}

	/**
	 * The other way that insert fails: a concurrent recovery for the same
	 * file got there first — the unique constraint is the serialization
	 * point. That row is theirs; only our pad goes.
	 */
	public function testHandleRestoreWithoutBindingLeavesARivalRecoverysRow(): void {
		$fileId = 93;
		$oldPadId = 'old-public-pad';
		$newPadId = 'r-old-public-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('unique constraint violation'));
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(false);
		$bindingService->expects($this->never())->method('deleteActiveBinding');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . $newPadId);
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willReturn('doc-before');

		$this->expectException(LifecycleException::class);
		$this->buildNoBindingRestoreService($bindingService, $etherpadClient, $oldPadId, $newPadId)->handleRestore($file);
	}

	/** A file with no binding row, whose frontmatter names a public pad. */
	private function buildNoBindingRestoreService(
		BindingService $bindingService,
		EtherpadClient $etherpadClient,
		string $oldPadId,
		string $newPadId,
	): LifecycleService {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => $oldPadId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => 'trashed'],
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/' . rawurlencode($oldPadId),
			isExternal: false,
			snapshotRev: -1,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'plain text', 'html' => '']);
		$padFileService->method('withRestoredSnapshot')->willReturn('doc-after');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abc123def456');

		return $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			secureRandom: $secureRandom,
		);
	}

	public function testHandleRestoreWithoutBindingSkipsExternalPadFile(): void {
		$fileId = 92;
		$oldPadId = 'ext.old';
		$origin = 'https://pad.remote.test';
		$remotePadId = 'RemotePad';
		$padUrl = $origin . '/p/' . $remotePadId;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$frontmatter = [
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => 'trashed',
			'pad_url' => $padUrl,
			'pad_origin' => $origin,
			'remote_pad_id' => $remotePadId,
		];
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('doc-before')->willReturn(new ParsedPadFile(
			frontmatter: $frontmatter,
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: $padUrl,
			isExternal: true,
			snapshotRev: -1,
		));
		$padFileService->expects($this->never())->method('getSnapshotPartsFromBody');
		$padFileService->expects($this->never())->method('withRestoredSnapshot');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('setText');
		$etherpadClient->expects($this->never())->method('setHTML');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('External.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = $this->lifecycleService(bindings: $bindingService, etherpad: $etherpadClient, padFiles: $padFileService)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('external_pad', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['pad_id']);
	}

	public function testHandleRestoreWithoutBindingSkipsExtPrefixWithIncompleteMetadata(): void {
		$fileId = 93;
		$oldPadId = 'ext.incomplete';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$frontmatter = [
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => 'trashed',
			'pad_url' => '',
		];
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('doc-before')->willReturn(new ParsedPadFile(
			frontmatter: $frontmatter,
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'external snapshot',
			'html' => '',
		]);
		$padFileService->expects($this->never())->method('withRestoredSnapshot');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('setText');
		$etherpadClient->expects($this->never())->method('setHTML');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('External.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = $this->lifecycleService(bindings: $bindingService, etherpad: $etherpadClient, padFiles: $padFileService)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('external_pad', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['pad_id']);
	}

	public function testHandleRestoreWithoutBindingSkipsDisallowedExternalUrl(): void {
		$fileId = 94;
		$oldPadId = 'ext.disallowed';
		$origin = 'https://pad.remote.test';
		$remotePadId = 'RemotePad';
		$padUrl = $origin . '/p/' . $remotePadId;

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$frontmatter = [
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => 'trashed',
			'pad_url' => $padUrl,
			'pad_origin' => $origin,
			'remote_pad_id' => $remotePadId,
		];
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('doc-before')->willReturn(new ParsedPadFile(
			frontmatter: $frontmatter,
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: $padUrl,
			isExternal: true,
			snapshotRev: -1,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'external snapshot',
			'html' => '',
		]);
		$padFileService->expects($this->never())->method('withRestoredSnapshot');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('setText');
		$etherpadClient->expects($this->never())->method('setHTML');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('External.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = $this->lifecycleService(bindings: $bindingService, etherpad: $etherpadClient, padFiles: $padFileService)->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('external_pad', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['pad_id']);
	}

	public function testRecoverFromSnapshotProvisionsFreshPadWhenBindingMissing(): void {
		$fileId = 701;
		$oldPadId = 'orphaned-pad';
		$newPadId = 'r-orphaned-pad-recover123abc';
		$newPadUrl = 'https://pad.example.test/p/' . rawurlencode($newPadId);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with($fileId, $newPadId, BindingService::ACCESS_PUBLIC);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: [
				'pad_id' => $oldPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/' . rawurlencode($oldPadId),
			isExternal: false,
			snapshotRev: -1,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'recovered content',
			'html' => '',
		]);
		$padFileService->method('withRestoredSnapshot')->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->once())->method('setText')->with($newPadId, 'recovered content');
		$etherpadClient->method('buildPadUrl')->with($newPadId)->willReturn($newPadUrl);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('recover123abc');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Imported.pad');
		$file->method('getContent')->willReturn('doc-before');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			secureRandom: $secureRandom,
		)->recoverFromSnapshot($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		// Critical security property: the recovered pad ID is a fresh one,
		// never the pad_id parroted back from the frontmatter.
		$this->assertNotSame($oldPadId, $result['new_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	public function testRecoverFromSnapshotRollsBackWhenBindingRaceLosesToConcurrentRequest(): void {
		// Two parallel recoveries both pass the findByFileId pre-check.
		// The loser's createBinding hits the unique constraint and throws,
		// and we must NOT proceed to overwrite the file (which by now
		// belongs to the winner's recovery). The provisioned pad and any
		// partially created binding row are cleaned up.
		$fileId = 711;
		$oldPadId = 'orphaned-pad';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->willThrowException(new BindingException('duplicate key on file_id'));
		// File content must not be overwritten if we lose the race.
		$bindingService->expects($this->never())->method('deleteActiveBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => $oldPadId, 'access_mode' => BindingService::ACCESS_PUBLIC],
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'content', 'html' => '']);
		$padFileService->method('withRestoredSnapshot')->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad');
		$etherpadClient->expects($this->once())->method('setText');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		// Loser must clean up its freshly provisioned pad.
		$etherpadClient->expects($this->once())->method('deletePad');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('race12345abc');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Orphan.pad');
		$file->method('getContent')->willReturn('doc-before');
		// Critical: we never touch the file when we lose the binding race.
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			secureRandom: $secureRandom,
		)->recoverFromSnapshot($file);
	}

	public function testRecoverFromSnapshotRefusesWhenBindingAlreadyExists(): void {
		$fileId = 702;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'pad_id' => 'already-linked',
			'state' => BindingService::STATE_ACTIVE,
			'access_mode' => BindingService::ACCESS_PUBLIC,
		]);
		$bindingService->expects($this->never())->method('createBinding');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Linked.pad');
		$file->expects($this->never())->method('putContent');

		$service = $this->lifecycleService(bindings: $bindingService);

		$this->expectException(PadAlreadyHasBindingException::class);
		$service->recoverFromSnapshot($file);
	}

	public function testRecoverFromSnapshotRejectsNonPadFile(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(703);
		$file->method('getName')->willReturn('Notes.txt');

		$service = $this->lifecycleService(bindings: $bindingService);

		$this->expectException(NotAPadFileException::class);
		$service->recoverFromSnapshot($file);
	}

	// ------------------------------------------------------------------
	// Wrapper tests for trashByPath / restoreByPath / recoverByFileId.
	// These were previously in PadLifecycleOperationServiceTest and now
	// live here since the reshape logic moved into LifecycleService.
	// They verify that the public wrappers resolve the node, call the
	// underlying lifecycle step, and reshape the result into the public
	// shape expected by controllers.
	// ------------------------------------------------------------------

	public function testTrashByPathReshapesSkippedResult(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('Test.pad');

		// The row is read - an undecided restore is handed back whatever the
		// setting says - but an active one is left as it is.
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn([
			'file_id' => 42,
			'pad_id' => 'pad-a',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_ACTIVE,
		]);
		$bindingService->expects($this->never())->method('transition');
		$bindingService->expects($this->never())->method('deleteByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method($this->anything());

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Test.pad')
			->willReturn($file);

		$service = $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			// delete_on_trash disabled => skipped before any Etherpad work.
			deleteOnTrash: false,
			nodes: $userNodeResolver,
			paths: new PathNormalizer(),
		);

		$result = $service->trashByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'delete_on_trash_disabled',
		], $result);
	}

	public function testTrashByPathReshapesTrashedResult(): void {
		$fileId = 21;
		$padId = 'pad-abc';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn([
				'file_id' => $fileId,
				'pad_id' => $padId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_ACTIVE,
			]);
		$bindingService->expects($this->once())->method('deleteByFileId')->with($fileId);

		$padFileService = $this->buildSnapshotWritingPadFileService();

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('deletePad')->with($padId);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Test.pad');
		$file->method('getContent')->willReturn('doc-before');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Test.pad')
			->willReturn($file);

		$service = $this->lifecycleService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			nodes: $userNodeResolver,
			paths: new PathNormalizer(),
		);

		$result = $service->trashByPath('alice', '/Test.pad');

		$this->assertSame('/Test.pad', $result['file']);
		$this->assertSame(LifecycleService::RESULT_TRASHED, $result['status']);
		$this->assertIsInt($result['deleted_at']);
		$this->assertTrue($result['snapshot_persisted']);
		$this->assertFalse($result['delete_pending']);
		$this->assertArrayNotHasKey('reason', $result);
	}

	public function testTrashByPathRejectsEmptyPath(): void {
		$service = $this->lifecycleService(paths: new PathNormalizer());

		$this->expectException(\InvalidArgumentException::class);
		$service->trashByPath('alice', '   ');
	}

	public function testRestoreByPathReshapesSkippedResult(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('Notes.txt'); // not a .pad => skipped

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Test.pad')
			->willReturn($file);

		$service = $this->lifecycleService(bindings: $bindingService, nodes: $userNodeResolver, paths: new PathNormalizer());

		$result = $service->restoreByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'not_pad_file',
		], $result);
	}

	public function testRecoverByFileIdReshapesSkippedExternalPadResult(): void {
		$fileId = 51;

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('readPad')
			->with('doc')
			->willReturn(new ParsedPadFile(
				frontmatter: ['pad_id' => 'ext.abc', 'access_mode' => BindingService::ACCESS_PUBLIC],
				body: '',
				padId: 'ext.abc',
				accessMode: BindingService::ACCESS_PUBLIC,
				padUrl: '',
				isExternal: true,
				snapshotRev: -1,
			));

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Ext.pad');
		$file->method('getContent')->willReturn('doc');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeById')
			->with('alice', $fileId)
			->willReturn($file);

		$service = $this->lifecycleService(
			bindings: $bindingService,
			padFiles: $padFileService,
			nodes: $userNodeResolver,
			paths: new PathNormalizer(),
		);

		$result = $service->recoverByFileId('alice', $fileId);

		$this->assertSame([
			'file_id' => $fileId,
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'external_pad',
		], $result);
	}

}
