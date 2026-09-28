<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Exception\PadFileNotWritableException;
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
use OCP\Lock\LockedException;
use OCP\Files\File;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\PadFiles;
use OCA\EtherpadNextcloud\Tests\Support\WiresTheLifecycle;

/**
 * A .pad file keeps its pad through the trash, and gets a new one from its
 * snapshot when it has none: when Etherpad lost it while the file was
 * away, and for a file with no row at all - from the trash, and in the
 * recovery an open offers.
 */
class RestoreServiceTest extends TestCase {
	use PadFiles;
	use WiresTheLifecycle;

	/** The revision the restored document was given, as the builder's formatter last saw it. */
	private ?int $restoredRevision = null;
	/** The pad the last file written in a test names. */
	private string $restoredPadId = '';

	/**
	 * A file seen deleted for good that comes back from the trash was not
	 * deleted after all: its row is active again, and while Etherpad has the
	 * pad the file keeps it as it is.
	 */
	public function testARowSeenDeletedWhoseFileIsBackKeepsItsPad(): void {
		$fileId = 81;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('transition')
			->with($fileId, 'old-pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_ACTIVE)
			->willReturn(true);
		$bindingService->expects($this->never())->method('rebind');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->with('old-pad')->willReturn(7);
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, state: BindingService::STATE_PENDING_DELETE)->restore($file);

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'pad_present'], $result);
	}

	/**
	 * The faults a debug instance injects for the end-to-end tests strike
	 * where a restore reads and writes its file: a read lock leaves the
	 * file to its next open, a write that fails leaves the file as it was
	 * and the restore failed.
	 */
	public function testARestoreMeetsTheFaultsInjectedIntoIt(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('transition');
		$bindingService->expects($this->never())->method('rebind');
		$file = $this->padFile(83, 'Restored.pad');
		$file->expects($this->never())->method('getContent');

		$result = $this->buildRowRestoreService(83, 'old-pad', $bindingService, $this->createMock(EtherpadClient::class), testFaults: $this->faultStriking(TestFaults::RESTORE_READ_LOCK))->restore($file);

		$this->assertSame(['skipped', 'file_unreadable'], [$result['status'], $result['reason']]);

		foreach ([TestFaults::RESTORE_WRITE_LOCK, TestFaults::RESTORE_WRITE_FAIL] as $fault) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->method('rebind')->willReturn(true);
			$file = $this->padFile(84, 'Restored.pad');
			$file->expects($this->never())->method('putContent');
			try {
				$this->buildRowRestoreService(84, 'old-pad', $bindingService, $this->buildEtherpadWithoutThePad(), testFaults: $this->faultStriking($fault))->restore($file);
				$this->fail($fault . ': the restore went through.');
			} catch (LifecycleException) {
				$this->addToAssertionCount(1);
			}
		}
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

		$result = $this->buildRowRestoreService($fileId, 'old-pad', $this->createMock(BindingService::class), $etherpadClient, accessMode: 'something-else')
			->restore($this->padFile($fileId, 'Restored.pad'));

		$this->assertSame(LifecycleResult::SKIPPED, $result['status']);
		$this->assertSame('unknown_access_mode', $result['reason']);
	}

	/**
	 * No replacement can be made - Etherpad gone while it is seeded - and
	 * the row stays on the lost pad: the pad is the file's, only lost, and
	 * the next open offers the new one again.
	 */
	public function testRestoreKeepsTheRowWhenNoReplacementCanBeMade(): void {
		$fileId = 87;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('deleteActiveBinding');
		$bindingService->expects($this->never())->method('transition');
		$bindingService->expects($this->never())->method('rebind');

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->method('createPad')->willThrowException(new \RuntimeException('Connection timed out'));

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
	}

	/**
	 * Seeding can fail after the pad is made. Nothing names it yet - the
	 * claim comes later - so it goes, and the file and its row are left as
	 * they were.
	 */
	public function testRestoreRemovesAReplacementItCouldNotSeed(): void {
		$fileId = 95;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('rebind');
		$bindingService->expects($this->never())->method('deleteActiveBinding');

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->method('setText')->willThrowException(new \RuntimeException('Connection reset'));
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
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
			->with($fileId, 'old-pad', BindingService::STATE_ACTIVE, $newPadId, BindingService::STATE_ACTIVE)
			->willReturn(false);
		$bindingService->expects($this->never())->method('transition');

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);

		$this->assertSame(LifecycleResult::SKIPPED, $result['status']);
		$this->assertSame('binding_state_transition_conflict', $result['reason']);
	}

	/**
	 * A write that fails after the claim leaves the row naming a pad the
	 * file never learned of. The row goes back onto the old pad, where the
	 * file still points, and the replacement goes.
	 */
	public function testRestoreMovesTheClaimBackWhenTheWriteFails(): void {
		$fileId = 88;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$rebinds = [];
		$bindingService->method('rebind')->willReturnCallback(static function (int $id, string $from, string $fromState, string $to, string $toState) use (&$rebinds): bool {
			$rebinds[] = [$from, $fromState, $to, $toState];
			return true;
		});
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(false);
		$bindingService->expects($this->never())->method('deleteActiveBinding');

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->willThrowException(new \RuntimeException('disk full'));

		try {
			$this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
			$this->fail('the failed write should reach the caller');
		} catch (LifecycleException) {
		}
		$active = BindingService::STATE_ACTIVE;
		$this->assertSame([['old-pad', $active, $newPadId, $active], [$newPadId, $active, 'old-pad', $active]], $rebinds);
	}

	/**
	 * Seeding a new pad takes a while, so the file is asked again before the
	 * claim: deleted meanwhile, and the new pad goes, with nothing claimed
	 * and nothing written - a write through the old node would make a new
	 * file where the restored one was.
	 */
	public function testANewPadIsLetGoWhenTheFileWasDeletedWhileItWasSeeded(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('rebind');
		$bindingService->method('isBoundTo')->willReturn(false);
		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('deletePad')->with('r-old-pad-abc123def456');
		$nodes = $this->createMock(UserNodeResolver::class);
		// Gone after the seeding.
		$nodes->expects($this->once())->method('hasMoved')->willReturn(true);
		$file = $this->padFile(91, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildRowRestoreService(91, 'old-pad', $bindingService, $etherpadClient, nodes: $nodes)->restore($file);

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'file_moved'], $result);
	}

	/**
	 * Where the file is cannot be told after the seeding - the database
	 * gone, say: the new pad goes, nothing is claimed or written, and the
	 * restore fails and says so.
	 */
	public function testANewPadIsLetGoWhenTheFileCannotBeFoundAfterTheSeeding(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('rebind');
		$bindingService->method('isBoundTo')->willReturn(false);
		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('deletePad')->with('r-old-pad-abc123def456');
		$nodes = $this->createMock(UserNodeResolver::class);
		$nodes->method('hasMoved')->willThrowException(new \RuntimeException('database went away'));
		$file = $this->padFile(92, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildRowRestoreService(92, 'old-pad', $bindingService, $etherpadClient, nodes: $nodes)->restore($file);
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

		$result = $this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);

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
		$bindingService->method('deleteActiveBinding')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->willThrowException(new \RuntimeException('connection lost'));

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('createPad')->with('r-old-pad-abc123def456');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
	}

	/**
	 * A claim that throws on a row that answers with another pad did not
	 * land - but that is a failure, not a lost race: the file is not
	 * written, the row still naming the old pad stays, and the replacement,
	 * known not to be named, goes.
	 */
	public function testRestoreKeepsTheRowOfAClaimThatFailedWithoutLanding(): void {
		$fileId = 94;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('rebind')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(false);
		$bindingService->expects($this->never())->method('deleteActiveBinding');
		$bindingService->expects($this->never())->method('transition');

		$etherpadClient = $this->buildEtherpadWithoutThePad();
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
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

		$result = $this->buildRowRestoreService($fileId, $oldPadId, $bindingService, $etherpadClient, accessMode: BindingService::ACCESS_PROTECTED)->restore($file);

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
			->with($fileId, $oldPadId, BindingService::STATE_ACTIVE, $newPadId, BindingService::STATE_ACTIVE)
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

		$result = $this->buildRowRestoreService(
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

		$result = $this->buildRowRestoreService($fileId, $oldPadId, $bindingService, $etherpadClient, accessMode: BindingService::ACCESS_PROTECTED, logger: $logger)->restore($file);
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

		$result = $this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->restore($file);
		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * A pad under the row's id without a single revision, while the file's
	 * snapshot holds more, is not the pad the file knew: a public pad
	 * Etherpad made anew, empty, when someone visited its address. Taken
	 * back, it would show the file empty and let a sync write that over the
	 * snapshot. The file gets a pad made from its snapshot, and the other is
	 * left where it is.
	public function testRestoreReplacesAPadThatIsBehindTheSnapshot(): void {
		$fileId = 96;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('transition');
		$bindingService->expects($this->once())
			->method('rebind')
			->with($fileId, 'old-pad', BindingService::STATE_ACTIVE, $newPadId, BindingService::STATE_ACTIVE)
			->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(
			static fn (string $padId): int => $padId === 'old-pad' ? 0 : 1,
		);
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->padFile($fileId, 'Restored.pad');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = $this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, snapshotRev: 500)
			->restore($file);

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame($newPadId, $result['new_pad_id']);
		// The new pad's own count, so the file is in sync with it at once.
		$this->assertSame(1, $this->restoredRevision);
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
		$this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, state: BindingService::STATE_PENDING_DELETE)->restore($this->padFile($fileId, 'Restored.pad'));
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
			$this->buildRowRestoreService(104, $oldPadId, $bindingService, $etherpadClient)->restore($this->padFile(104, 'Restored.pad'));
		}

		$this->assertSame('r-nc-abcdefghijklmnopqrstuvwx-abc123def456', $created[0]);
		$this->assertSame('r-legacy-' . str_repeat('x', 28) . '-abc123def456', $created[1]);
		$this->assertSame(50, strlen($created[1]));
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
	 * A file without a row is asked again after the seeding too: deleted
	 * meanwhile, and the new pad goes, with no row made and nothing written.
	 */
	public function testRestoreWithoutBindingLetsTheNewPadGoWhenTheFileWasDeletedWhileItWasSeeded(): void {
		$fileId = 94;
		$newPadId = 'r-old-public-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(false);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . $newPadId);
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$nodes = $this->createMock(UserNodeResolver::class);
		$nodes->expects($this->once())->method('hasMoved')->with($fileId, '/alice/files/Restored.pad')->willReturn(true);
		$file = $this->padFile($fileId, 'Restored.pad');
		$file->method('getPath')->willReturn('/alice/files/Restored.pad');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildNoBindingRestoreService($bindingService, $etherpadClient, 'old-public-pad', nodes: $nodes)->restore($file);

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'file_moved'], $result);
	}

	/**
	 * A copy never opened has no row either, but its pad was not deleted:
	 * it lives on with the original, whose row names it. Back from the
	 * trash, it gets no pad of its own; its open offers the original.
	 */
	public function testRestoreLeavesACopyToItsOpen(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$bindingService->method('findByPadId')->with('old-pad')->willReturn(new Binding(fileId: 12, padId: 'old-pad', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
		$bindingService->expects($this->never())->method('createBinding');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createPad');
		$file = $this->padFile(95, 'Copy.pad');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildNoBindingRestoreService($bindingService, $etherpadClient, 'old-pad')->restore($file);

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'copy_of_another_file'], $result);
	}

	/**
	 * A file back from the trash whose row stayed active takes its pad back
	 * as it is - unless Etherpad lost it while the file was away: then a new
	 * pad is made from the file at once, where an open would only offer it.
	 * Etherpad not answering, or a file that cannot be read, leave the row
	 * to the next open.
	 */
	public function testRestoreOfAnActiveRowMakesALostPadAnewAtOnce(): void {
		$cases = [
			'there' => [7, 'pad_present'],
			'gone' => [new EtherpadRefusedException('padID does not exist'), null],
			'made anew, empty' => [0, null],
			'no answer' => [new EtherpadClientException('Etherpad API request failed'), RestoreService::REASON_PRESENCE_UNKNOWN],
		];
		foreach ($cases as $case => [$answer, $skipped]) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->expects($skipped === null ? $this->once() : $this->never())
				->method('rebind')
				->with(701, 'old-pad', BindingService::STATE_ACTIVE, 'r-old-pad-abc123def456', BindingService::STATE_ACTIVE)
				->willReturn(true);
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function (string $padId) use ($answer): int {
				if ($padId !== 'old-pad') {
					return 1;
				}
				if ($answer instanceof \Throwable) {
					throw $answer;
				}
				return $answer;
			});
			$etherpadClient->expects($skipped === null ? $this->once() : $this->never())->method('createPad');
			$file = $this->padFile(701, 'Back.pad');
			$file->expects($skipped === null ? $this->once() : $this->never())->method('putContent');

			$result = $this->buildRowRestoreService(701, 'old-pad', $bindingService, $etherpadClient, snapshotRev: 5, state: BindingService::STATE_ACTIVE)
				->restore($file);

			$this->assertSame($skipped === null ? LifecycleResult::RESTORED : LifecycleResult::SKIPPED, $result['status'], $case);
			if ($skipped !== null) {
				$this->assertSame($skipped, $result['reason'], $case);
			}
		}

		// A row naming another pad than the file is not the restore's to replace.
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(new Binding(fileId: 701, padId: 'row-pad', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
		$bindingService->expects($this->never())->method('rebind');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willThrowException(new EtherpadRefusedException('padID does not exist'));
		$etherpadClient->expects($this->never())->method('createPad');
		$result = $this->restoreServiceReadingOldPad($bindingService, $etherpadClient)->restore($this->padFile(701, 'Back.pad'));
		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'row_names_other_pad'], $result);

		// Etherpad refusing to say is no answer to wait for: the restore's
		// listener reports it.
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willThrowException(new EtherpadRefusedException('apikey is invalid'));
		$etherpadClient->expects($this->never())->method('createPad');
		try {
			$this->buildRowRestoreService(701, 'old-pad', $this->createMock(BindingService::class), $etherpadClient, snapshotRev: 5, state: BindingService::STATE_ACTIVE)
				->restore($this->padFile(701, 'Back.pad'));
			$this->fail('a refusal taken for an answer');
		} catch (LifecycleException $e) {
			$this->assertInstanceOf(EtherpadRefusedException::class, $e->getPrevious());
		}

		$unreadable = $this->createMock(File::class);
		$unreadable->method('getId')->willReturn(701);
		$unreadable->method('getName')->willReturn('Back.pad');
		$unreadable->method('getContent')->willThrowException(new \RuntimeException('cannot read'));
		$result = $this->buildRowRestoreService(701, 'old-pad', $this->createMock(BindingService::class), $this->createMock(EtherpadClient::class), state: BindingService::STATE_ACTIVE)
			->restore($unreadable);
		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'file_unreadable'], $result);
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

	/**
	 * A file whose row names a pad Etherpad has lost gets a new pad from its
	 * own content: the row moves onto it, the file names it. Lost is gone
	 * altogether, or - a public pad visited at its address - made anew with
	 * no revision while the file's snapshot holds more; the empty pad made
	 * anew is left alone.
	 */
	public function testRecoveryMakesANewPadForARowWhosePadIsLost(): void {
		foreach (['gone' => null, 'made anew empty' => 0] as $case => $revisions) {
			$fileId = 700;
			$newPadId = 'r-old-pad-abc123def456';
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->expects($this->once())
				->method('rebind')
				->with($fileId, 'old-pad', BindingService::STATE_ACTIVE, $newPadId, BindingService::STATE_ACTIVE)
				->willReturn(true);

			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function (string $padId) use ($revisions): int {
				if ($padId !== 'old-pad') {
					return 1;
				}
				return $revisions ?? throw new EtherpadRefusedException('padID does not exist');
			});
			$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
			$etherpadClient->expects($this->never())->method('deletePad');
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('info')->with('A pad Etherpad had lost was made anew from its file.', $this->anything());

			$file = $this->padFile($fileId, 'Lost.pad');
			$file->expects($this->once())->method('putContent')->with('doc-after');

			$result = $this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, logger: $logger, snapshotRev: 5, state: BindingService::STATE_ACTIVE)
				->recoverFromSnapshot($file);

			$this->assertSame(LifecycleResult::RESTORED, $result['status'], $case);
			$this->assertSame($newPadId, $result['new_pad_id'], $case);
		}
	}

	/**
	 * A row is refused as before unless its pad is lost: a pad Etherpad
	 * has, even one behind the file's snapshot (as restores in 1.1.0-beta.1
	 * left them); a row of a file seen deleted for good; a row naming another
	 * pad than the file.
	 */
	public function testRecoveryRefusesARowWhosePadIsNotLost(): void {
		// The last two with a pad that is lost: only the row can refuse them.
		$cases = [
			'there' => [BindingService::STATE_ACTIVE, 'old-pad', 7],
			'behind, not empty' => [BindingService::STATE_ACTIVE, 'old-pad', 2],
			'seen deleted for good' => [BindingService::STATE_PENDING_DELETE, 'old-pad', null],
			'another pad in the file' => [BindingService::STATE_ACTIVE, 'row-pad', null],
		];
		foreach ($cases as $case => [$state, $rowPad, $revisions]) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->expects($this->never())->method('rebind');
			$bindingService->expects($this->never())->method('createBinding');
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturnCallback(
				static fn (): int => $revisions ?? throw new EtherpadRefusedException('padID does not exist'),
			);
			$etherpadClient->expects($this->never())->method('createPad');
			$file = $this->padFile(702, 'Linked.pad');
			$file->expects($this->never())->method('putContent');
			if ($rowPad === 'old-pad') {
				$service = $this->buildRowRestoreService(702, 'old-pad', $bindingService, $etherpadClient, snapshotRev: 5, state: $state);
			} else {
				$bindingService->method('findByFileId')->willReturn(new Binding(fileId: 702, padId: $rowPad, accessMode: BindingService::ACCESS_PUBLIC, state: $state));
				$service = $this->restoreServiceReadingOldPad($bindingService, $etherpadClient);
			}

			try {
				$service->recoverFromSnapshot($file);
				$this->fail($case . ': not refused');
			} catch (PadAlreadyHasBindingException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * A replacement of an active row's lost pad that fails keeps the row:
	 * its pad is the file's, only lost, and the next open offers the new
	 * pad again. A write that fails after the claim moves the row back onto
	 * the old pad, where the file still points, and the new pad goes; only
	 * a row that cannot be moved back goes with it.
	 */
	public function testAFailedReplacementOfALostPadKeepsTheRow(): void {
		foreach (['moved back' => true, 'not moved back' => false] as $case => $movesBack) {
			$fileId = 706;
			$newPadId = 'r-old-pad-abc123def456';
			$bindingService = $this->createMock(BindingService::class);
			$rebinds = [];
			$bindingService->method('rebind')->willReturnCallback(static function (int $id, string $from, string $fromState, string $to) use (&$rebinds, $movesBack, $newPadId): bool {
				$rebinds[] = [$from, $to];
				return $from !== $newPadId || $movesBack;
			});
			$bindingService->method('isBoundTo')->willReturn(!$movesBack);
			$bindingService->expects($this->never())->method('deleteInState');
			$bindingService->expects($movesBack ? $this->never() : $this->once())->method('deleteActiveBinding')->with($fileId, $newPadId)->willReturn(true);
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willThrowException(new EtherpadRefusedException('padID does not exist'));
			$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);
			$file = $this->padFile($fileId, 'Lost.pad');
			$file->method('putContent')->willThrowException(new LockedException('locked'));

			try {
				$this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient)->recoverFromSnapshot($file);
				$this->fail($case . ': the failure should reach the caller');
			} catch (LifecycleException) {
			}

			$this->assertSame([['old-pad', $newPadId], [$newPadId, 'old-pad']], $rebinds, $case);
		}
	}

	/**
	 * A write can land and still throw - a hook after it failing, say.
	 * Row and file then both name the new pad: it is kept, with a warning,
	 * rather than one of them taken back to leave them naming two pads.
	 */
	public function testAWriteThatLandedKeepsTheNewPad(): void {
		$fileId = 708;
		$newPadId = 'r-old-pad-abc123def456';
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('rebind')->with($fileId, 'old-pad', BindingService::STATE_ACTIVE, $newPadId, BindingService::STATE_ACTIVE)->willReturn(true);
		$bindingService->expects($this->never())->method('deleteActiveBinding');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(static fn (string $padId): int => $padId === 'old-pad' ? throw new EtherpadRefusedException('padID does not exist') : 1);
		$etherpadClient->expects($this->never())->method('deletePad');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('A restored .pad file reported a failed write, yet names its new pad; the new pad is kept.', $this->anything());
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Lost.pad');
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getContent')->willReturnOnConsecutiveCalls('doc-before', 'doc-after');
		$file->method('putContent')->willThrowException(new \RuntimeException('a hook after the write failed'));

		$result = $this->buildRowRestoreService($fileId, 'old-pad', $bindingService, $etherpadClient, logger: $logger)->recoverFromSnapshot($file);

		$this->assertSame(LifecycleResult::RESTORED, $result['status']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * A public pad whose file holds nothing saved has nothing to make a new
	 * pad from: a restore says so, and Etherpad is not asked.
	 */
	public function testARestoreDoesNotAskAboutAPublicPadWithNothingSaved(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('rebind');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('getRevisionsCount');

		$result = $this->buildRowRestoreService(709, 'old-pad', $bindingService, $etherpadClient, snapshotRev: -1)
			->restore($this->padFile(709, 'Untouched.pad'));

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'nothing_saved'], $result);
	}

	/** An active row whose access mode the app does not know stays as it is: no new pad, no row taken away. */
	public function testAnActiveRowOfAnUnknownModeStays(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('rebind');
		$bindingService->expects($this->never())->method('deleteActiveBinding');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willThrowException(new EtherpadRefusedException('padID does not exist'));
		$etherpadClient->expects($this->never())->method('createPad');

		$result = $this->buildRowRestoreService(710, 'old-pad', $bindingService, $etherpadClient, accessMode: 'mystery')
			->restore($this->padFile(710, 'Odd.pad'));

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'unknown_access_mode'], $result);
	}

	/**
	 * A file back without a row that cannot be read - a legacy Ownpad link
	 * without metadata, say - is left to its open, quietly: it is no
	 * failure of the restore, and nothing tries it again.
	 */
	public function testAFileWithoutRowThatCannotBeReadIsLeftToItsOpen(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$bindingService->expects($this->never())->method('findByPadId');
		$bindingService->expects($this->never())->method('createBinding');
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willThrowException(new MissingFrontmatterException('Missing YAML frontmatter in .pad file.'));
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createPad');

		$result = $this->restoreService(bindings: $bindingService, etherpad: $etherpadClient, padFiles: $padFileService)
			->restore($this->padFile(711, 'Legacy.pad', "[InternetShortcut]\nURL=https://pad.example.test/p/legacy\n"));

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => RestoreService::REASON_FILE_UNREADABLE], $result);
	}

	/** Etherpad not answering is no answer at all: nothing is made, and the caller hears of it. */
	public function testRecoveryStopsWhenEtherpadDoesNotAnswer(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('rebind');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willThrowException(new EtherpadClientException('Etherpad API request failed'));
		$etherpadClient->expects($this->never())->method('createPad');

		$this->expectException(EtherpadClientException::class);
		$this->buildRowRestoreService(703, 'old-pad', $bindingService, $etherpadClient, snapshotRev: 5, state: BindingService::STATE_ACTIVE)
			->recoverFromSnapshot($this->padFile(703, 'Lost.pad'));
	}

	/** A restore service whose file names 'old-pad', over the row $bindings holds. */
	private function restoreServiceReadingOldPad(BindingService $bindings, EtherpadClient $etherpadClient): RestoreService {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(frontmatter: [], body: 'body', padId: 'old-pad', accessMode: BindingService::ACCESS_PUBLIC, padUrl: '', isExternal: false, snapshotRev: 5));
		return $this->restoreService(bindings: $bindings, etherpad: $etherpadClient, padFiles: $padFileService);
	}

	/**
	 * A recovery writes the file, and on a row moves the row first: whoever
	 * may not change the file - a read-only share - is refused before the
	 * row is read or Etherpad is asked, row or none.
	 */
	public function testRecoveryIsRefusedToWhoeverMayNotChangeTheFile(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method($this->anything());
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(705);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('isUpdateable')->willReturn(false);
		$file->expects($this->never())->method('putContent');

		$this->expectException(PadFileNotWritableException::class);
		$this->restoreService(bindings: $bindingService, etherpad: $etherpadClient)->recoverFromSnapshot($file);
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

	/**
	 * A restore of a file whose row names $oldPadId, active unless $state
	 * says otherwise, and whose snapshot was taken at $snapshotRev: with
	 * Etherpad saying the pad does not exist, one it has lost. Restored, the
	 * file reads 'doc-after'.
	 */
	private function buildRowRestoreService(
		int $fileId,
		string $oldPadId,
		BindingService&MockObject $bindingService,
		EtherpadClient $etherpadClient,
		string $accessMode = BindingService::ACCESS_PUBLIC,
		string $html = '',
		?LoggerInterface $logger = null,
		int $snapshotRev = 5,
		string $state = BindingService::STATE_ACTIVE,
		?LoggerInterface $padLifecycleLogger = null,
		?TestFaults $testFaults = null,
		?UserNodeResolver $nodes = null,
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
		// 'doc-after' is the file once written, naming the pad it was written for.
		$padFileService->method('readPad')->willReturnCallback(fn (string $content): ParsedPadFile => match ($content) {
			'doc-before' => $parsedPad,
			'doc-after' => new ParsedPadFile(frontmatter: [], body: 'body', padId: $this->restoredPadId, accessMode: BindingService::ACCESS_PUBLIC, padUrl: '', isExternal: false, snapshotRev: 1),
		});
		$padFileService->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn(['text' => 'plain text', 'html' => $html]);
		$padFileService->method('withRestoredSnapshot')->willReturnCallback(
			function (ParsedPadFile $pad, string $text, string $html, string $padId, string $padUrl, int $revision = -1): string {
				$this->restoredRevision = $revision;
				$this->restoredPadId = $padId;
				return 'doc-after';
			},
		);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abc123def456');

		return $this->restoreService(
			bindings: $bindingService,
			etherpad: $etherpadClient,
			padFiles: $padFileService,
			logger: $logger,
			padLifecycleLogger: $padLifecycleLogger,
			secureRandom: $secureRandom,
			testFaults: $testFaults,
			nodes: $nodes,
		);
	}

	/** A file with no binding row, whose frontmatter names a public pad. */
	private function buildNoBindingRestoreService(
		BindingService $bindingService,
		EtherpadClient $etherpadClient,
		string $oldPadId,
		?LoggerInterface $logger = null,
		?UserNodeResolver $nodes = null,
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
			nodes: $nodes,
		);
	}
}
