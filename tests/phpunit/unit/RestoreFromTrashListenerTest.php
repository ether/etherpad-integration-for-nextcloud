<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RestoreFromTrashListener;
use OCA\EtherpadNextcloud\Service\LifecycleService;
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
	 * The error path logs the file id, and on the node above that read
	 * throws too. A second exception there would replace the one being
	 * reported and leave no trace of the real cause — which is exactly what
	 * made the Nextcloud 31 failure show up as a bare NotFoundException.
	 */
	public function testLifecycleErrorIsRethrownEvenWhenTheIdCannotBeLogged(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willThrowException(new NotFoundException());
		$file->method('getPath')->willReturn('/alice/files/notes.pad');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($file);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$boom = new \RuntimeException('lifecycle exploded');
		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('handleRestore')->willThrowException($boom);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->createMock(IUserSession::class),
			$rootFolder,
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
}
