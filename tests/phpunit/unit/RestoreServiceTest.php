<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleResult;
use OCA\EtherpadNextcloud\Service\RestoreService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Service\SettleOutcome;
use OCA\EtherpadNextcloud\Service\TestFaults;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCP\Files\File;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\PadFiles;
use OCA\EtherpadNextcloud\Tests\Support\WiresTheLifecycle;

/**
 * A .pad file gets its pad back: its own while Etherpad still has it, a
 * new one from the snapshot when it is gone or behind, and the row left
 * waiting while Etherpad cannot say - from the trash, in the sweep, and for
 * a file with no row at all.
 */
class RestoreServiceTest extends TestCase {
	use PadFiles;
	use WiresTheLifecycle;

	/** The revision the restored document was given, as the builder's formatter last saw it. */
	private ?int $restoredRevision = null;

	/**
	 * A binding waits in pending_delete because its pad could not be deleted,
	 * and that pad may hold edits the snapshot missed. While Etherpad still
	 * has it, the file gets it back as it is.
	 */
	public function testRestoreGivesTheFileBackItsOwnPadWhileItExists(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame('old-pad', $result['old_pad_id']);
		$this->assertSame('old-pad', $result['new_pad_id']);
	}

	/**
	 * The faults a debug instance injects for the end-to-end tests strike
	 * where a restore reads and writes its file: a read lock leaves the
	 * restore waiting for a later check, a write that fails leaves the file
	 * as it was and the restore failed.
	 */
	public function testARestoreMeetsTheFaultsInjectedIntoIt(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with(83, 'old-pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_RESTORE_PENDING)
			->willReturn(true);
		$file = $this->padFile(83, 'Restored.pad');
		$file->expects($this->never())->method('getContent');

		$result = $this->buildPendingDeleteRestoreService(83, 'old-pad', $bindingService, $this->createMock(EtherpadClient::class), testFaults: $this->faultStriking(TestFaults::RESTORE_READ_LOCK))->restore($file);

		$this->assertSame(['skipped', 'file_unreadable'], [$result['status'], $result['reason']]);

		foreach ([TestFaults::RESTORE_WRITE_LOCK, TestFaults::RESTORE_WRITE_FAIL] as $fault) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->method('rebind')->willReturn(true);
			$file = $this->padFile(84, 'Restored.pad');
			$file->expects($this->never())->method('putContent');
			try {
				$this->buildPendingDeleteRestoreService(84, 'old-pad', $bindingService, $this->buildEtherpadWithoutThePad(), testFaults: $this->faultStriking($fault))->restore($file);
				$this->fail($fault . ': the restore went through.');
			} catch (LifecycleException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/** No answer is not an answer: the pad is neither reused nor replaced. */
	public function testRestoreKeepsThePadForALaterCheckWhenEtherpadCannotSay(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		// Etherpad's silence is warned about, with its cause, where it is met;
		// the restore adds a note, not a second warning for the same event.
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->once())->method('info')->with($this->stringContains('later check'), $this->anything());

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, logger: $logger)->restore($file);

		$this->assertSame(LifecycleResult::SKIPPED, $result['status']);
		$this->assertSame('pad_presence_unknown', $result['reason']);
	}

	/**
	 * An access mode nobody knows must not become an unprotected pad - and
	 * must not take the file's own restore down either, so it is skipped
	 * the way every other unusable binding is.
	 */
	public function testRestoreSkipsABindingWithAnUnknownAccessMode(): void {
		$fileId = 91;
		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('createGroup');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $this->createMock(BindingService::class), $etherpadClient, accessMode: 'something-else')
			->restore($this->padFile($fileId, 'Restored.pad'));

		$this->assertSame(LifecycleResult::SKIPPED, $result['status']);
		$this->assertSame('unknown_access_mode', $result['reason']);
	}

	/**
	 * Etherpad has already said the row's pad is gone, so a row still
	 * naming it is released when no replacement can be made: a later check
	 * could only reach the same answer, and without the row the file offers
	 * its own recovery at once.
	 */
	public function testRestoreReleasesTheRowWhenNoReplacementCanBeMade(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
	}

	/**
	 * Seeding can fail after the pad is made. Nothing names it yet - the
	 * claim comes later - so it goes, and the file is left as it was.
	 */
	public function testRestoreRemovesAReplacementItCouldNotSeed(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
	}

	/**
	 * Two restores of one file can both get as far as a replacement pad. The
	 * row decides which of them writes; the other leaves the file to the
	 * winner and takes its own pad with it.
	 */
	public function testRestoreWritesNothingWhenAnotherRestoreClaimedTheRow(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);

		$this->assertSame(LifecycleResult::SKIPPED, $result['status']);
		$this->assertSame('binding_state_transition_conflict', $result['reason']);
	}

	/**
	 * A write that fails after the claim leaves the row naming a pad the
	 * file never learned of. That row goes, and the replacement with it.
	 */
	public function testRestoreRemovesTheClaimWhenTheWriteFails(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->willThrowException(new \RuntimeException('disk full'));

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
	}

	/**
	 * A claim can commit and still throw. Taken for a refusal, it would
	 * discard the very pad the row now names - so the row is asked.
	 */
	public function testRestoreKeepsAClaimThatLandedWithoutSayingSo(): void {
		$fileId = 89;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('rebind')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(true);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		// Never the replacement; and the old pad, which Etherpad has called
		// gone, takes no call either.
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * A claim that throws, on a row that then cannot be read, is a claim
	 * nobody has seen. The file is not written and the restore fails; the
	 * replacement stays, since the row may be naming it.
	 */
	public function testRestoreWritesNothingOnAClaimThatCannotBeSettled(): void {
		$fileId = 92;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('rebind')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('deleteInState')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->willThrowException(new \RuntimeException('connection lost'));

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('createPad')->with('r-old-pad-abc123def456');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
	}

	/**
	 * A claim that throws on a row that answers with another pad did not
	 * land - but that is a failure, not a lost race: the file is not
	 * written, the row still naming the old pad is released, and the
	 * replacement, known not to be named, goes.
	 */
	public function testRestoreReleasesTheRowOfAClaimThatFailedWithoutLanding(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
	}

	/**
	 * A trash can reach the file while its restore is still writing, and
	 * leave the row naming the replacement in pending_delete. That pad is
	 * the row's now, not the failed restore's to throw away.
	 */
	public function testRestoreLeavesAReplacementATrashHasTakenOver(): void {
		$fileId = 93;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('rebind')->willReturn(true);
		// The trash moved the row on from active, so it is not removed.
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(true);
		$bindingService->method('deleteActiveBinding')->willReturn(false);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->willThrowException(new \RuntimeException('disk full'));

		// Nothing is left for the restore to release, and the log says so.
		$debug = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(static function (string $message) use (&$debug): void {
			$debug[] = $message;
		});

		try {
			$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, logger: $logger)->restore($file);
			$this->fail('Expected the restore to fail.');
		} catch (LifecycleException) {
		}
		$this->assertContains('Left a binding a failed restore no longer holds.', $debug);
	}

	/**
	 * A restore that lost the row to another takes down only the pad it
	 * made itself. What is left of the pad it would have replaced - here an
	 * empty group - is the winner's to clear.
	 */
	public function testARestoreThatLostTheRowLeavesTheSupersededGroup(): void {
		$fileId = 85;
		$oldPadId = 'g.OLDGROUPID12345$p-old';
		$newPadId = 'g.NEWGROUPID12345$restored-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('rebind')->willReturn(false);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->method('createGroup')->willReturn('g.NEWGROUPID12345');
		$etherpadClient->method('createGroupPad')->willReturn($newPadId);
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.NEWGROUPID12345');
		$etherpadClient->expects($this->never())->method('listPads');

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildPendingDeleteRestoreService($fileId, $oldPadId, $bindingService, $etherpadClient, accessMode: BindingService::ACCESS_PROTECTED)->restore($file);

		$this->assertSame('binding_state_transition_conflict', $result['reason']);
	}

	/**
	 * Etherpad has said the old pad is gone, but a protected pad's group can
	 * outlive it with nothing in it - and once the row names the replacement,
	 * nothing leads back to that group.
	 */
	public function testRestoreTakesTheSupersededGroupWithIt(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService(
			$fileId,
			$oldPadId,
			$bindingService,
			$etherpadClient,
			accessMode: BindingService::ACCESS_PROTECTED,
			html: '<p>plain text</p>',
			logger: $logger,
		)->restore($file);

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame($oldPadId, $result['old_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * And it stays a success when that leftover cannot be removed: the
	 * restore is already recorded, and the user's file works either way.
	 */
	public function testRestoreStillSucceedsWhenTheSupersededPadSurvives(): void {
		$fileId = 85;
		$oldPadId = 'g.OLDGROUPID12345$p-old';
		$newPadId = 'g.NEWGROUPID12345$restored-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('rebind')->willReturn(true);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->method('createGroup')->willReturn('g.NEWGROUPID12345');
		$etherpadClient->method('createGroupPad')->willReturn($newPadId);
		$etherpadClient->method('listPads')->with('g.OLDGROUPID12345')->willReturn([]);
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.OLDGROUPID12345')
			->willThrowException(new \RuntimeException('still down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not remove what was left of a pad that is gone.');

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService($fileId, $oldPadId, $bindingService, $etherpadClient, accessMode: BindingService::ACCESS_PROTECTED, logger: $logger)->restore($file);
		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
	}

	/**
	 * A public pad Etherpad has just called gone leaves nothing behind to
	 * clear up, so the restore asks nothing more about it. A call would only
	 * wait for Etherpad on the user's restore, to hear the same answer.
	 */
	public function testARestoreAsksNothingMoreAboutAPublicPadThatIsGone(): void {
		$fileId = 86;
		$newPadId = 'r-old-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('rebind')->willReturn(true);

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->once())->method('setText')->with($newPadId, 'plain text');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * A pad under the row's id with fewer revisions than the file's
	 * snapshot is not the pad the file knew: created again since, or back
	 * from an older backup. Taken back, it would show the file empty or old
	 * and let a sync write that over the snapshot. The file gets a pad made
	 * from its snapshot, and the other is left to whoever wrote into it.
	 */
	public function testRestoreReplacesAPadThatIsBehindTheSnapshot(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, logger: $logger, snapshotRev: 500, padLifecycleLogger: $logger)
			->restore($file);

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame($newPadId, $result['new_pad_id']);
		// The new pad's own count, so the file is in sync with it at once.
		$this->assertSame(1, $this->restoredRevision);
	}

	/**
	 * Without the file there is no revision to hold the pad to, so the row
	 * waits for a later check rather than being settled blind.
	 */
	public function testRestoreKeepsTheRowWaitingWhileTheFileCannotBeRead(): void {
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

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);

		$this->assertSame(LifecycleResult::SKIPPED, $result['status']);
		$this->assertSame('file_unreadable', $result['reason']);
	}

	/**
	 * And when the row cannot be moved to wait either, the restore fails
	 * without a word of its own: the warning about the unreadable file is
	 * for a row that waits, and this one does not. The failure is the
	 * caller's to report, once.
	 */
	public function testAnUnreadableFileWhoseRowCannotWaitFailsWithoutAWordOfItsOwn(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('transition')->willThrowException(new \RuntimeException('connection lost'));
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(98);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willThrowException(new \RuntimeException('locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService(98, 'old-pad', $bindingService, $this->createMock(EtherpadClient::class), logger: $logger)->restore($file);
	}

	/**
	 * A deletion owed is settled on restore even once deleting on trash is
	 * switched off: the file is back, and a row left in pending_delete
	 * would keep it from opening for good.
	 */
	public function testRestoreSettlesAnOwedDeletionWithTheSettingOff(): void {
		$fileId = 98;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_ACTIVE)
			->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);

		$result = $this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, deleteOnTrash: false)
			->restore($this->padFile($fileId, 'Restored.pad'));

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
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
			->settleWaitingFile($this->padFile($fileId, 'Restored.pad'), new RunBudget(new FixedClock(), 5.0));

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
			$file = $this->padFile(106, 'Restored.pad');
			$file->expects($this->never())->method('putContent');

			$outcome = $this->buildPendingDeleteRestoreService(106, 'old-pad', $bindingService, $etherpadClient, logger: $logger, snapshotRev: 7, state: BindingService::STATE_RESTORE_PENDING, padLifecycleLogger: $logger)
				->settleWaitingFile($file, new RunBudget(new FixedClock(), 5.0));

			$this->assertSame(SettleOutcome::Settled, $outcome, $case);
		}
	}

	/** @return iterable<string, array{int|string, bool, bool, SettleOutcome, list<string>}> */
	public static function groupsOfAReleasedRow(): iterable {
		// Etherpad's answer, whether the row is still the sweep's to release, whether the group can be removed.
		yield 'gone' => ['padID does not exist', true, true, SettleOutcome::Settled, ['row', 'group']];
		yield 'gone, the group stays' => ['padID does not exist', true, false, SettleOutcome::Settled, ['row']];
		yield 'behind' => [0, true, true, SettleOutcome::Settled, ['row']];
		yield 'gone, the row taken meanwhile' => ['padID does not exist', false, true, SettleOutcome::Left, []];
	}

	/**
	 * A protected pad that is gone leaves its group, and once the sweep has
	 * released the row, what is left of it goes, as after a replacement. A
	 * pad behind the snapshot is there and stays; a row another flow took
	 * is not the sweep's to clear up after. A group that cannot be removed
	 * is logged, and the row stays released.
	 *
	 * @param list<string> $steps
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('groupsOfAReleasedRow')]
	public function testAReleasedRowTakesTheEmptyGroupOfAPadThatIsGone(int|string $answer, bool $released, bool $removable, SettleOutcome $expected, array $steps): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$old-pad';
		$order = [];
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('deleteInState')->willReturnCallback(static function () use (&$order, $released): bool {
			if ($released) {
				$order[] = 'row';
			}
			return $released;
		});
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(static fn (): int => is_int($answer) ? $answer : throw new \RuntimeException($answer));
		$etherpadClient->method('listPads')->willReturn([]);
		$etherpadClient->method('deleteGroup')->willReturnCallback(static function () use (&$order, $removable): void {
			if (!$removable) {
				throw new \RuntimeException('Connection reset');
			}
			$order[] = 'group';
		});
		$etherpadClient->expects($this->never())->method('deletePad');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($removable ? $this->never() : $this->once())
			->method('warning')
			->with('Could not remove what was left of a pad that is gone.', $this->callback(
				static fn (array $context): bool => $context['fileId'] === 106 && $context['padId'] === $padId
			));

		$outcome = $this->buildPendingDeleteRestoreService(106, $padId, $bindingService, $etherpadClient, accessMode: BindingService::ACCESS_PROTECTED, logger: $logger, snapshotRev: 7, state: BindingService::STATE_RESTORE_PENDING)
			->settleWaitingFile($this->padFile(106, 'Restored.pad'), new RunBudget(new FixedClock(), 5.0));

		$this->assertSame($expected, $outcome);
		$this->assertSame($steps, $order);
	}

	/** And a restore settles a row that still waits undecided, with the setting off as well. */
	public function testRestoreSettlesAnUndecidedRestoreWithTheSettingOff(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with(108, 'old-pad', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_ACTIVE)
			->willReturn(true);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);

		$result = $this->buildPendingDeleteRestoreService(108, 'old-pad', $bindingService, $etherpadClient, state: BindingService::STATE_RESTORE_PENDING, deleteOnTrash: false)
			->restore($this->padFile(108, 'Restored.pad'));

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
	}

	/**
	 * A database that fails on the way reaches the caller as the restore's
	 * failure, like every other one - not as a driver exception carrying
	 * its statement.
	 */
	public function testRestoreReportsABindingFailureAsItsOwn(): void {
		$fileId = 100;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('transition')->willThrowException(new \RuntimeException('connection lost'));

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);

		$this->expectException(LifecycleException::class);
		$this->buildPendingDeleteRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($this->padFile($fileId, 'Restored.pad'));
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
			$this->buildPendingDeleteRestoreService(104, $oldPadId, $bindingService, $etherpadClient)->restore($this->padFile(104, 'Restored.pad'));
		}

		$this->assertSame('r-nc-abcdefghijklmnopqrstuvwx-abc123def456', $created[0]);
		$this->assertSame('r-legacy-' . str_repeat('x', 28) . '-abc123def456', $created[1]);
		$this->assertSame(50, strlen($created[1]));
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
		$row = new Binding(125, 'old-pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_PENDING_DELETE);
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturnOnConsecutiveCalls($row, null);
		$bindingService->expects($this->once())->method('transition')->willReturn(false);
		$bindingService->expects($this->once())->method('createBinding')->with(125, 'r-old-pad-abc123def456', BindingService::ACCESS_PUBLIC);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);
		$etherpadClient->expects($this->once())->method('createPad')->with('r-old-pad-abc123def456');
		$file = $this->padFile(125, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildPendingDeleteRestoreService(125, 'old-pad', $bindingService, $etherpadClient)->restore($file);

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame('r-old-pad-abc123def456', $result['new_pad_id']);

		$sweepRow = $this->createMock(BindingService::class);
		// Gone on the second read here too: the sweep still writes nothing.
		$sweepRow->method('findByFileId')->willReturnOnConsecutiveCalls($row, null);
		$sweepRow->method('transition')->willReturn(false);
		$sweepRow->expects($this->never())->method('createBinding');
		$sweeper = $this->createMock(EtherpadClient::class);
		$sweeper->method('getRevisionsCount')->willReturn(3);
		$sweeper->expects($this->never())->method('createPad');
		$file = $this->padFile(125, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->assertSame(
			SettleOutcome::Left,
			$this->buildPendingDeleteRestoreService(125, 'old-pad', $sweepRow, $sweeper)->settleWaitingFile($file, new RunBudget(new FixedClock(), 20.0)),
		);
	}

	public function testRestoreWithoutBindingRecreatesManagedPublicPad(): void {
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

		$result = $this->restoreService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			secureRandom: $secureRandom,
		)->restore($file);

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame($oldPadId, $result['old_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * `createBinding` can commit and still throw, and the write that would
	 * have made the file agree with the row never runs. A flag says no row
	 * while a row is there naming the pad about to be deleted — which is
	 * how a binding ends up pointing at a pad that is gone.
	 */
	public function testRestoreWithoutBindingRemovesARowItsFailedWriteLeftBehind(): void {
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
		$this->buildNoBindingRestoreService($bindingService, $etherpadClient, $oldPadId)->restore($file);
	}

	/**
	 * The other way that insert fails: a concurrent recovery for the same
	 * file got there first — the unique constraint is the serialization
	 * point. That row is theirs; only our pad goes.
	 */
	public function testRestoreWithoutBindingLeavesARivalRecoverysRow(): void {
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

		$file = $this->padFile($fileId, 'Restored.pad');
		// The file is the rival's to write.
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildNoBindingRestoreService($bindingService, $etherpadClient, $oldPadId)->restore($file);
	}

	/**
	 * With deleting on trash switched off, a file back without a row gets no
	 * new pad: whether to make one is the setting's call, as deleting the old
	 * one was.
	 */
	public function testRestoreWithoutBindingFollowsTheSetting(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method($this->anything());
		$file = $this->padFile(95, 'Unbound.pad');
		$file->expects($this->never())->method('putContent');

		$result = $this->restoreService(bindings: $bindingService, etherpad: $etherpadClient, deleteOnTrash: false)->restore($file);

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'delete_on_trash_disabled'], $result);
	}

	/**
	 * A file back without a row that names a pad on another server gets no
	 * pad here: nothing is made, bound or written. Here its metadata is
	 * incomplete and only the `ext.` id says so - the case a look at the
	 * frontmatter alone would miss. Which files name one is
	 * ParsedPadFileTest's.
	 */
	public function testRestoreWithoutBindingSkipsAnExternalPad(): void {
		$fileId = 92;
		$oldPadId = 'ext.old';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('doc-before')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => $oldPadId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => 'trashed'],
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
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

		$result = $this->restoreService(bindings: $bindingService, etherpad: $etherpadClient, padFiles: $padFileService)->restore($file);

		$this->assertSame(LifecycleResult::SKIPPED, $result['status']);
		$this->assertSame('external_pad', $result['reason']);
	}

	/**
	 * A recovery takes the path of a restore without a row: a fresh pad,
	 * never the id the file names, bound and written - and a line at info
	 * level that says the file got its pad back this way.
	 */
	public function testARecoveryMakesAFreshPadAndSaysSo(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with(701)->willReturn(null);
		$bindingService->expects($this->once())->method('createBinding')->with(701, 'r-orphaned-pad-abc123def456', BindingService::ACCESS_PUBLIC);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad')->with('r-orphaned-pad-abc123def456');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/r-orphaned-pad-abc123def456');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Pad recovered from snapshot.', ['app' => 'etherpad_nextcloud', 'fileId' => 701]);
		$file = $this->padFile(701, 'Imported.pad');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildNoBindingRestoreService($bindingService, $etherpadClient, 'orphaned-pad', logger: $logger)->recoverFromSnapshot($file);

		$this->assertSame(LifecycleResult::restored('orphaned-pad', 'r-orphaned-pad-abc123def456'), $result);
	}

	/** A recovery that does not happen - the file names an external pad - says nothing of one. */
	public function testASkippedRecoveryDoesNotSayItRecovered(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile([], '', 'ext.abc', BindingService::ACCESS_PUBLIC, '', true, -1));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		$result = $this->restoreService(bindings: $bindingService, padFiles: $padFileService, logger: $logger)->recoverFromSnapshot($this->padFile(704, 'External.pad'));

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'external_pad'], $result);
	}

	public function testRecoverFromSnapshotRefusesWhenBindingAlreadyExists(): void {
		$fileId = 702;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(new Binding(fileId: $fileId, padId: 'already-linked', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
		$bindingService->expects($this->never())->method('createBinding');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Linked.pad');
		$file->expects($this->never())->method('putContent');

		$service = $this->restoreService(bindings: $bindingService);

		$this->expectException(PadAlreadyHasBindingException::class);
		$service->recoverFromSnapshot($file);
	}

	public function testRecoverFromSnapshotRejectsNonPadFile(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(703);
		$file->method('getName')->willReturn('Notes.txt');

		$service = $this->restoreService(bindings: $bindingService);

		$this->expectException(NotAPadFileException::class);
		$service->recoverFromSnapshot($file);
	}

	/** Test faults with this one striking, as on a debug instance the admin API set it on. */
	private function faultStriking(string $fault): TestFaults {
		$testFaults = $this->createMock(TestFaults::class);
		$testFaults->method('isActive')->willReturnCallback(static fn (string $candidate): bool => $candidate === $fault);
		return $testFaults;
	}

	/** Etherpad answers for the old pad that it does not exist. */
	private function buildEtherpadWithoutThePad(): EtherpadClient&MockObject {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willThrowException(new \RuntimeException('padID does not exist'));
		$etherpadClient->method('buildPadUrl')->willReturnCallback(static fn (string $padId): string => 'https://pad.example.test/p/' . rawurlencode($padId));
		return $etherpadClient;
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
		?LoggerInterface $padLifecycleLogger = null,
		?TestFaults $testFaults = null,
	): RestoreService {
		$bindingService->method('findByFileId')->with($fileId)->willReturn(new Binding(fileId: $fileId, padId: $oldPadId, accessMode: $accessMode, state: $state));

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

		return $this->restoreService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			deleteOnTrash: $deleteOnTrash,
			logger: $logger,
			padLifecycleLogger: $padLifecycleLogger,
			secureRandom: $secureRandom,
			testFaults: $testFaults,
		);
	}

	/** A file with no binding row, whose frontmatter names a public pad. */
	private function buildNoBindingRestoreService(
		BindingService $bindingService,
		EtherpadClient $etherpadClient,
		string $oldPadId,
		?LoggerInterface $logger = null,
	): RestoreService {
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

		return $this->restoreService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			logger: $logger,
			secureRandom: $secureRandom,
		);
	}
}
