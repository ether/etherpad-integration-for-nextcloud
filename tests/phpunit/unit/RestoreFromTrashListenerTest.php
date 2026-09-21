<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RestoreFromTrashListener;
use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Tests\Support\WiresALifecycleService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RestoreFromTrashListenerTest extends TestCase {
	use WiresALifecycleService;

	/**
	 * One failure, one entry. The flow no longer reports its own, because
	 * it cannot see the ways out that end above its try; the caller can,
	 * and does. Both halves have to be watched by the same logger, or the
	 * test agrees with any arrangement of the two - and the failure has to
	 * be raised inside the flow's try, because that is where the entry
	 * that was removed used to be written.
	 *
	 * A real service rather than a mock: a mocked one has no inside.
	 */
	public function testAFlowFailureIsReportedOnceAndOnlyByTheCaller(): void {
		$fileId = 4711;
		$boom = new \RuntimeException('the storage went away');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn([
			'file_id' => $fileId,
			'pad_id' => 'old-pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_PENDING_DELETE,
		]);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Notes.pad');
		// Inside restoreFlow's own try, which is where the removed entry
		// was written and the only place it could ever have fired.
		$file->method('getContent')->willThrowException($boom);

		$logger = $this->createMock(LoggerInterface::class);
		$this->closeEveryLevelExcept($logger, 'error');
		$logger->expects($this->once())
			->method('error')
			->with(
				$this->anything(),
				$this->callback(function (array $context) use ($fileId, $boom): bool {
					$this->assertSame($fileId, $context['fileId']);
					// The cause, not just the wrapper the flow threw.
					$this->assertStringContainsString($boom->getMessage(), $context['error_origin']);
					return true;
				}),
			);

		$listener = new RestoreFromTrashListener(
			$this->lifecycleServiceOver($bindingService, $logger),
			$this->createMock(IUserSession::class),
			$this->createMock(IRootFolder::class),
			$logger,
		);

		$this->expectException(LifecycleException::class);
		$listener->handle($this->restoreEventFor($file));
	}

	/** Every reporting level but one, so a repeat cannot hide on another. */
	private function closeEveryLevelExcept(LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger, string $kept): void {
		foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
			if ($level !== $kept) {
				$logger->expects($this->never())->method($level);
			}
		}
	}

	private function restoreEventFor(File $file): Event {
		return new class($file) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		};
	}

	public function testTypedRestoreEventRestoresTargetFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($file)
			->willReturn(['status' => LifecycleService::RESULT_RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->createMock(IUserSession::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$listener->handle(new class($file) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}

	public function testLegacyRestoreHookResolvesFilePathInUserFolder(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())
			->method('get')
			->with('G - Jacobs Test Gruppe/Neues Pad 9.pad')
			->willReturn($file);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($file)
			->willReturn(['status' => LifecycleService::RESULT_RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$userSession,
			$rootFolder,
			$this->createMock(LoggerInterface::class),
		);

		$listener->handleLegacyHook([
			'filePath' => '/G - Jacobs Test Gruppe/Neues Pad 9.pad',
			'trashPath' => 'files_trashbin/files/Neues Pad 9.pad.d1777397341',
		]);
	}

	/**
	 * Nextcloud 31 hands the event a node that is not resolvable yet, so
	 * reading its id throws. That exception used to escape the listener and
	 * abort the restore itself — a .pad could not be brought back from the
	 * trash at all, through the web UI, WebDAV or `occ trashbin:restore`.
	 * The owner is taken from the path because occ has no session.
	 */
	public function testUnresolvableEventTargetIsLookedUpByPath(): void {
		$unresolvable = $this->createMock(File::class);
		$unresolvable->method('getId')->willThrowException(new NotFoundException());
		$unresolvable->method('getPath')->willReturn('/alice/files/notes.pad');

		$resolved = $this->createMock(File::class);
		$resolved->method('getId')->willReturn(42);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())
			->method('get')
			->with('notes.pad')
			->willReturn($resolved);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->never())->method('getUser');

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($resolved)
			->willReturn(['status' => LifecycleService::RESULT_RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$userSession,
			$rootFolder,
			$this->createMock(LoggerInterface::class),
		);

		$listener->handle(new class($unresolvable) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}

	/**
	 * A node can go stale between being handed over and the lifecycle
	 * failing, so the error path must survive an id it can no longer read.
	 * Logging that threw a second time used to replace the exception being
	 * reported, which is why the Nextcloud 31 failure showed up in the log
	 * as a bare NotFoundException with nothing about its cause.
	 */
	public function testLifecycleErrorIsRethrownEvenWhenTheIdCannotBeLogged(): void {
		$reads = 0;
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturnCallback(function () use (&$reads): int {
			$reads++;
			if ($reads === 1) {
				return 42;
			}
			throw new NotFoundException();
		});

		$boom = new \RuntimeException('lifecycle exploded');
		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('handleRestore')->willThrowException($boom);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->createMock(IUserSession::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->expectExceptionObject($boom);
		$listener->handle(new class($file) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}

	/**
	 * The fallback is held to the same standard as the node it replaces:
	 * handleRestore() reads the id on its first line, so passing on one that
	 * still throws would put the restore-aborting behaviour straight back.
	 */
	public function testUnresolvableFallbackIsSkippedInsteadOfPassedOn(): void {
		$unresolvable = $this->createMock(File::class);
		$unresolvable->method('getId')->willThrowException(new NotFoundException());
		$unresolvable->method('getPath')->willReturn('/alice/files/notes.pad');

		$stillUnresolvable = $this->createMock(File::class);
		$stillUnresolvable->method('getId')->willThrowException(new NotFoundException());

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($stillUnresolvable);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->never())->method('handleRestore');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->createMock(IUserSession::class),
			$rootFolder,
			$logger,
		);

		$listener->handle(new class($unresolvable) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}

	/** A path that is not /<user>/files/... is skipped, and says so. */
	public function testUnexpectedPathShapeIsSkippedWithAReason(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willThrowException(new NotFoundException());
		$node->method('getPath')->willReturn('/somewhere/else.pad');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->never())->method('handleRestore');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->createMock(IUserSession::class),
			$rootFolder,
			$logger,
		);

		$listener->handle(new class($node) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}
}
