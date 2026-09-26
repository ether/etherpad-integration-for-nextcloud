<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\LeavingPadsListener;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\GoneFileSweep;
use OCP\EventDispatcher\Event;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\User\Events\BeforeUserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What leaving Files marks, and when: a move to the trash marks at once, a
 * delete past it once done, a user deleted every file of their home. A
 * restore clears what it brings back. Nothing it does may stop the trash,
 * the delete or the restore it hears of.
 */
class LeavingPadsListenerTest extends TestCase {
	/** @var list<array{string,list<int>}> what the listener did, in order */
	private array $calls = [];
	private BindingService $bindings;
	private GoneFileSweep $sweep;
	private IRootFolder $rootFolder;
	private IUserMountCache $mounts;
	private IUserSession $session;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->calls = [];
		$this->bindings = $this->createMock(BindingService::class);
		$this->bindings->method('fileIdsUnder')->willReturnCallback(function (int $folderId): array {
			$this->calls[] = ['under', [$folderId]];
			return [9 => [21, 22], 3 => [31, 7]][$folderId] ?? [];
		});
		$this->bindings->method('fileIdsOnStorage')->willReturnCallback(function (int $storageId): array {
			$this->calls[] = ['storage', [$storageId]];
			return [41, 42];
		});
		$this->bindings->method('markTrashed')->willReturnCallback(function (array $fileIds): void {
			$this->calls[] = ['mark', $fileIds];
		});
		$this->bindings->method('clearTrashed')->willReturnCallback(function (array $fileIds): void {
			$this->calls[] = ['clear', $fileIds];
		});
		$this->sweep = $this->createMock(GoneFileSweep::class);
		$this->sweep->method('discardDeleted')->willReturnCallback(function (array $fileIds): void {
			$this->calls[] = ['discard', $fileIds];
		});
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->mounts = $this->createMock(IUserMountCache::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * A move to the trash marks the file, or every file under a folder. A
	 * delete looks the same up, and marks nothing yet. A user deleted marks
	 * every file on their home storage, found through the mount cache.
	 */
	public function testMarksWhatLeavesFiles(): void {
		$alice = $this->user('alice');
		$this->mounts->method('getMountsForUser')->willReturn([$this->mount('/alice/files/Team/', 7), $this->mount('/alice/', 5)]);
		$cases = [
			'a .pad file to the trash' => [$this->trashEvent($this->file(7, 'Notes.pad')), [['mark', [7]]]],
			'another file to the trash' => [$this->trashEvent($this->file(8, 'Notes.txt')), [['mark', []]]],
			'a folder to the trash' => [$this->trashEvent($this->folder(9)), [['under', [9]], ['mark', [21, 22]]]],
			'a .pad file about to be deleted' => [new BeforeNodeDeletedEvent($this->file(7, 'Notes.pad')), []],
			'a folder about to be deleted' => [new BeforeNodeDeletedEvent($this->folder(9)), [['under', [9]]]],
			'a user deleted' => [new BeforeUserDeletedEvent($alice), [['storage', [5]], ['mark', [41, 42]]]],
			'anything else' => [new Event(), []],
		];
		foreach ($cases as $case => [$event, $expected]) {
			$this->calls = [];
			$this->listener()->handle($event);
			$this->assertSame($expected, $this->calls, $case);
		}
	}

	/** A user with no home in the mount cache never logged in, and has no files to mark. */
	public function testAUserWithoutAHomeMarksNothing(): void {
		$this->mounts->method('getMountsForUser')->willReturn([$this->mount('/alice/files/Team/', 7)]);

		$this->listener()->handle(new BeforeUserDeletedEvent($this->user('alice')));

		$this->assertSame([['mark', []]], $this->calls);
	}

	/**
	 * A move to the trash comes after the delete it is part of, and marks
	 * what that looked up, rather than looking it up again. It still hands
	 * it on once the delete is done: a move to the trash that fails falls
	 * back to a delete past it, and the sweep passes by what the trash took.
	 */
	public function testATrashMarksWhatItsDeleteLookedUp(): void {
		$listener = $this->listener();
		$folder = $this->folder(9);

		$listener->handle(new BeforeNodeDeletedEvent($folder));
		$listener->handle($this->trashEvent($folder));
		$listener->handle(new NodeDeletedEvent($folder));

		$this->assertSame([['under', [9]], ['mark', [21, 22]], ['discard', [21, 22]]], $this->calls);
	}

	/**
	 * Once a delete past the trash is done, what it looked up goes to the
	 * sweep, each file once - a file on its own and in a folder deleted in
	 * one request, or left by a delete that failed before it - and is
	 * forgotten.
	 */
	public function testHandsOnWhatADeleteLookedUpOnceItIsDone(): void {
		$listener = $this->listener();
		$pad = $this->file(7, 'Notes.pad');

		$listener->handle(new BeforeNodeDeletedEvent($pad));
		$listener->handle(new BeforeNodeDeletedEvent($this->folder(3)));
		$listener->handle(new BeforeNodeDeletedEvent($this->folder(9)));
		$listener->handle(new NodeDeletedEvent($pad));
		$listener->handle(new NodeDeletedEvent($pad));

		$this->assertSame([['under', [3]], ['under', [9]], ['discard', [7, 31, 21, 22]], ['discard', []]], $this->calls, 'the file in the folder once');
	}

	/**
	 * A restore clears the marks of what it brings back: a .pad file, or
	 * every file under a folder, once in a request. A node that cannot give
	 * its id yet - as the event's on Nextcloud 31 - is looked up again by
	 * its path. The legacy hook's path is the restoring user's.
	 */
	public function testARestoreClearsItsMarks(): void {
		$unresolved = $this->createMock(File::class);
		$unresolved->method('getId')->willThrowException(new \RuntimeException('not resolvable yet'));
		$unresolved->method('getPath')->willReturn('/alice/files/Other.pad');
		$this->rootFolder->method('get')->with('/alice/files/Other.pad')->willReturn($this->file(17, 'Other.pad'));
		$home = $this->createMock(Folder::class);
		$home->method('get')->with('/Team/sub')->willReturn($this->folder(9));
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($home);
		$this->session->method('getUser')->willReturn($this->user('alice'));
		$listener = $this->listener();

		$listener->handle($this->restoreEvent($this->file(7, 'Notes.pad')));
		$listener->handle($this->restoreEvent($this->file(8, 'Notes.txt')));
		$listener->handle($this->restoreEvent($this->folder(3)));
		$listener->handle($this->restoreEvent($unresolved));
		$listener->restoredPath('/Team/sub');
		$listener->handle($this->restoreEvent($this->folder(9)));

		$this->assertSame([['clear', [7]], ['clear', []], ['under', [3]], ['clear', [31, 7]], ['clear', [17]], ['under', [9]], ['clear', [21, 22]]], $this->calls, 'each node once: core raises the hook and the event');
	}

	/** Without a user to restore for, the hook's path says nothing. */
	public function testAHookWithoutAUserClearsNothing(): void {
		$this->listener()->restoredPath('/Team/sub');

		$this->assertSame([], $this->calls);
	}

	/** A mark that fails is a warning; the trash or restore goes ahead, and the sweep sets things right later. */
	public function testNothingThrows(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('markTrashed')->willThrowException(new \RuntimeException('the database went away'));
		$bindings->method('clearTrashed')->willThrowException(new \RuntimeException('the database went away'));
		$this->session->method('getUser')->willReturn($this->user('alice'));
		$this->rootFolder->method('getUserFolder')->willThrowException(new \RuntimeException('no home'));
		$this->logger->expects($this->exactly(3))->method('warning')->with('Could not keep the marks of pads leaving Files; the sweep finds them later.', $this->anything());
		$listener = new LeavingPadsListener($bindings, $this->sweep, $this->rootFolder, $this->mounts, $this->session, $this->logger);

		$listener->handle($this->trashEvent($this->file(7, 'Notes.pad')));
		$listener->handle($this->restoreEvent($this->file(7, 'Notes.pad')));
		$listener->restoredPath('/Notes.pad');
	}

	private function listener(): LeavingPadsListener {
		return new LeavingPadsListener($this->bindings, $this->sweep, $this->rootFolder, $this->mounts, $this->session, $this->logger);
	}

	private function file(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	private function folder(int $id): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		return $folder;
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function mount(string $mountPoint, int $storageId): ICachedMountInfo {
		$mount = $this->createMock(ICachedMountInfo::class);
		$mount->method('getMountPoint')->willReturn($mountPoint);
		$mount->method('getStorageId')->willReturn($storageId);
		return $mount;
	}

	/** The trash app's MoveToTrashEvent, which carries its node under getNode(). */
	private function trashEvent(object $node): Event {
		return new class($node) extends Event {
			public function __construct(private object $node) {
			}

			public function getNode(): object {
				return $this->node;
			}
		};
	}

	/** The trash app's NodeRestoredEvent, which carries the restored node under getTarget(). */
	private function restoreEvent(object $node): Event {
		return new class($node) extends Event {
			public function __construct(private object $node) {
			}

			public function getTarget(): object {
				return $this->node;
			}
		};
	}
}
