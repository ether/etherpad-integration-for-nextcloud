<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleResult;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadFileService;
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
use OCA\EtherpadNextcloud\Tests\Support\PadFiles;
use OCA\EtherpadNextcloud\Tests\Support\WiresTheLifecycle;

class LifecycleServiceTest extends TestCase {
	use PadFiles;
	use WiresTheLifecycle;

	/**
	 * A sweep can settle an undecided row while the same file goes to the
	 * trash. The trash then finds the row active and trashes the file as
	 * what it is now, rather than leaving an active row behind a file in
	 * the trash, with its pad orphaned once the trash is emptied.
	 */
	public function testHandleTrashTrashesARowASweepSettledMeanwhile(): void {
		$rows = [
			new Binding(109, 'old-pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_RESTORE_PENDING),
			new Binding(109, 'old-pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE),
		];
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturnCallback(static function () use (&$rows): Binding {
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

		$this->assertSame(LifecycleResult::TRASHED, $result['status']);
		$this->assertFalse($result['delete_pending']);
	}

	/**
	 * A database that fails while a file whose restore is undecided goes
	 * to the trash reaches the caller as the trash's failure, like every
	 * other one - not as a driver exception carrying its statement.
	 */
	public function testHandleTrashReportsABindingFailureOnAnUndecidedRestoreAsItsOwn(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('transition')->willThrowException(new \RuntimeException('connection lost'));
		$file = $this->undecidedPadFile(101, $bindingService);

		$this->expectException(LifecycleException::class);
		$this->lifecycleService(bindings: $bindingService)->handleTrash($file);
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
			$bindingService->method('findByFileId')->willReturn(new Binding(fileId: 110, padId: 'pad-kept', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
			$bindingService->expects($this->once())
				->method('transition')
				->with(110, 'pad-kept', BindingService::STATE_ACTIVE, BindingService::STATE_PENDING_DELETE)
				->willReturn(true);
			$bindingService->expects($this->never())->method('deleteByFileId');

			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->expects($this->never())->method('deletePad');
			// Past the file's snapshot, and still there after the text: a snapshot to write.
			$etherpadClient->method('getRevisionsCount')->willReturn(3);
			$etherpadClient->method('getText')->willReturn('text');
			$this->trashedSnapshotRev = 2;

			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn(110);
			$file->method('getName')->willReturn('Kept.pad');
			if ($case === 'file locked by its delete') {
				$file->method('getContent')->willThrowException(new LockedException('Kept.pad'));
				$file->expects($this->never())->method('putContent');
			} else {
				$file->method('getContent')->willReturn('doc-before');
				$file->expects($this->once())->method('putContent')->with('doc-after')->willThrowException(new \RuntimeException('disk full'));
			}

			// At trash time a miss is always news: the file's own trouble is a warning.
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($case === 'write refused' ? $this->once() : $this->never())->method('warning')
				->with('A trashed .pad file did not get its snapshot. Its pad is kept for now.', $this->callback(static fn (array $context): bool => $context['reason'] === 'write_failed'));

			$result = $this->buildTrashService($bindingService, $etherpadClient, logger: $logger)->handleTrash($file);

			$this->assertSame(LifecycleResult::TRASHED, $result['status'], $case);
			$this->assertTrue($result['delete_pending'], $case);
			$this->assertFalse($result['snapshot_persisted'], $case);
		}
	}

	/**
	 * A pad behind the file's snapshot - created again empty under the old
	 * id, or back from an older backup - is not the pad the file knew. Its
	 * content would take the place of the last good snapshot, so the trash
	 * writes nothing and deletes nothing: the deletion is owed, and the
	 * sweep's rule for such a pad decides. Before, the file ended up empty
	 * and the pad and row gone.
	 */
	public function testATrashDoesNotWriteAPadBehindTheFilesSnapshot(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(new Binding(fileId: 110, padId: 'pad-again', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
		$bindingService->expects($this->once())
			->method('transition')
			->with(110, 'pad-again', BindingService::STATE_ACTIVE, BindingService::STATE_PENDING_DELETE)
			->willReturn(true);
		$bindingService->expects($this->never())->method('deleteByFileId');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(0);
		$etherpadClient->expects($this->never())->method('getText');
		$etherpadClient->expects($this->never())->method('deletePad');
		$this->trashedSnapshotRev = 50;
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(110);
		$file->method('getName')->willReturn('Kept.pad');
		$file->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = $this->buildTrashService($bindingService, $etherpadClient)->handleTrash($file);

		$this->assertSame(LifecycleResult::TRASHED, $result['status']);
		$this->assertFalse($result['snapshot_persisted']);
		$this->assertTrue($result['delete_pending']);
	}

	/**
	 * A pad Etherpad no longer has when the trash deletes it is gone as
	 * surely as one the trash deleted: the row goes either way, and only an
	 * info line tells the two apart.
	 */
	public function testATrashFindingThePadGoneAlreadyTakesTheRow(): void {
		foreach (['gone already' => true, 'deleted now' => false] as $case => $gone) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->method('findByFileId')->willReturn(new Binding(fileId: 110, padId: 'pad-gone', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
			$bindingService->expects($this->never())->method('transition');
			$bindingService->expects($this->once())->method('deleteByFileId')->with(110);
			$etherpadClient = $this->createMock(EtherpadClient::class);
			// The file holds the pad's revision already, so nothing is written first.
			$etherpadClient->method('getRevisionsCount')->willReturn(4);
			$this->trashedSnapshotRev = 4;
			$delete = $etherpadClient->expects($this->once())->method('deletePad')->with('pad-gone');
			if ($gone) {
				$delete->willThrowException(new \RuntimeException('padID does not exist'));
			}
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->never())->method('warning');
			$logger->expects($gone ? $this->once() : $this->never())->method('info')
				->with('Pad already deleted while processing trash; deleting binding row.');
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn(110);
			$file->method('getName')->willReturn('Gone.pad');
			$file->method('getContent')->willReturn('doc-before');

			$result = $this->buildTrashService($bindingService, $etherpadClient, logger: $logger)->handleTrash($file);

			$this->assertSame(LifecycleResult::TRASHED, $result['status'], $case);
			$this->assertTrue($result['snapshot_persisted'], $case);
			$this->assertFalse($result['delete_pending'], $case);
		}
	}

	/**
	 * A file that already holds the pad's revision needs no new snapshot:
	 * the pad goes without a fetch or a write, at trash time as much as in
	 * the sweep (PendingBindingServiceTest).
	 */
	public function testNoSnapshotIsTakenThatTheFileHasAlready(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(new Binding(fileId: 110, padId: 'pad-current', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
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
		$this->assertSame(LifecycleResult::SKIPPED, $result['status']);
		$this->assertSame('not_pad_file', $result['reason']);
	}

	public function testHandleTrashMarksPendingDeleteWhenEtherpadDeleteFails(): void {
		$fileId = 21;
		$padId = 'pad-abc';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(new Binding(fileId: $fileId, padId: $padId, accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
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
		$this->assertSame(LifecycleResult::TRASHED, $result['status']);
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
			->willReturn(new Binding(fileId: $fileId, padId: $padId, accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
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
		$this->assertSame(LifecycleResult::SKIPPED, $result['status']);
		$this->assertSame('binding_state_transition_conflict', $result['reason']);
	}

	/**
	 * Trashed again while its restore is still undecided. The pad may hold
	 * the only current copy, so Etherpad is not touched; the row is owed a
	 * deletion again, as it was before the restore. With deleting on trash
	 * switched off too: nothing is deleted by it, and a row left waiting
	 * would keep the file from opening once it is back.
	 */
	public function testHandleTrashLeavesAnUndecidedPadAlone(): void {
		foreach (['setting on' => true, 'setting off' => false] as $case => $deleteOnTrash) {
			$bindingService = $this->createMock(BindingService::class);
			$bindingService->expects($this->once())
				->method('transition')
				->with(56, 'old-pad', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_PENDING_DELETE)
				->willReturn(true);
			$bindingService->expects($this->never())->method('deleteByFileId');
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->expects($this->never())->method($this->anything());
			$file = $this->undecidedPadFile(56, $bindingService);
			$file->expects($this->never())->method('putContent');

			$result = $this->lifecycleService(bindings: $bindingService, etherpad: $etherpadClient, deleteOnTrash: $deleteOnTrash)->handleTrash($file);

			$this->assertSame(LifecycleResult::TRASHED, $result['status'], $case);
			$this->assertTrue($result['delete_pending'], $case);
		}
	}

	/** A .pad file whose row waits undecided, naming 'old-pad'. */
	private function undecidedPadFile(int $fileId, BindingService&MockObject $bindingService): File&MockObject {
		$bindingService->method('findByFileId')->with($fileId)->willReturn(new Binding(fileId: $fileId, padId: 'old-pad', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_RESTORE_PENDING));
		return $this->padFile($fileId, 'Undecided.pad');
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
		$bindingService->method('findByFileId')->willReturn(new Binding(fileId: 42, padId: 'pad-a', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
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
			'status' => LifecycleResult::SKIPPED,
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
			->willReturn(new Binding(fileId: $fileId, padId: $padId, accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));
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
		$this->assertSame(LifecycleResult::TRASHED, $result['status']);
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
			'status' => LifecycleResult::SKIPPED,
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
			'status' => LifecycleResult::SKIPPED,
			'reason' => 'external_pad',
		], $result);
	}

}
