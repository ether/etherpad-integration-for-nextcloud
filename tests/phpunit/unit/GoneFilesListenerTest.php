<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\GoneFilesListener;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\Files_Trashbin\Events\BeforeNodeRestoredEvent;
use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which removals from the file cache mark a file as deleted for good, and
 * when the marks are written: a removal from a trash, one of a node being
 * deleted or under it, a user's home - never one a scan makes, and never
 * by the name.
 */
class GoneFilesListenerTest extends TestCase {
	private const HOME = 'home::alice';
	private const ROOT = 'local::/var/www/html/data/';

	/** @var list<array{string,list<int>}> what the listener wrote, in order */
	private array $calls = [];
	/** @var list<\Closure(): void> what the request runs when it ends */
	private array $atEnd = [];
	/** @var array<int,array{int,string}> by file id, where the file cache has a node */
	private array $places = [];
	/** @var list<int> files that have no row, which markIfGone() does not report as marked */
	private array $withoutRow = [];
	private BindingService $bindings;
	private IUserMountCache $mounts;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->calls = [];
		$this->atEnd = [];
		$this->places = [];
		$this->withoutRow = [];
		$this->bindings = $this->createMock(BindingService::class);
		$this->bindings->method('markIfGone')->willReturnCallback(function (array $fileIds): array {
			$this->calls[] = ['mark', $fileIds];
			return array_values(array_diff($fileIds, $this->withoutRow));
		});
		$this->bindings->method('markGone')->willReturnCallback(function (array $fileIds): void {
			$this->calls[] = ['mark home', $fileIds];
		});
		$this->bindings->method('placeOf')->willReturnCallback(fn (int $fileId): ?array => $this->places[$fileId] ?? null);
		$this->bindings->method('clearGone')->willReturnCallback(function (array $fileIds): void {
			$this->calls[] = ['clear', $fileIds];
		});
		$this->bindings->method('fileIdsOnStorage')->willReturnCallback(function (int $storageId): array {
			$this->calls[] = ['storage', [$storageId]];
			return [41, 42];
		});
		$this->mounts = $this->createMock(IUserMountCache::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * A removal from a trash counts, whoever makes it; so does the removal
	 * of a node being deleted, whatever the file's name. Versions and app
	 * data never count, and a `trash/` folder counts only on a team folder's
	 * own storage. A removal outside a delete - a scan's - does not.
	 *
	 * @return iterable<string,array{string,string,bool,bool}>
	 */
	public static function removals(): iterable {
		// storage, path, within a delete, counts
		yield 'from a user\'s trash' => [self::HOME, 'files_trashbin/files/Notes.pad.d100', false, true];
		yield 'from a team folder\'s trash on the root storage' => [self::ROOT, '__groupfolders/trash/3/Notes.pad.d100', false, true];
		yield 'from a team folder\'s trash on its own storage' => ['local::/var/www/html/data/__groupfolders/7/', 'trash/Notes.pad.d100', false, true];
		yield 'from a team folder\'s trash in an object store' => ['object::groupfolder:7.primary', 'trash/Notes.pad.d100', false, true];
		yield 'from a folder named trash elsewhere' => ['local::/mnt/share/', 'trash/Notes.pad', false, false];
		yield 'a trashed file\'s versions' => [self::HOME, 'files_trashbin/versions/Notes.pad.v1.d100', false, false];
		yield 'by a scan' => [self::HOME, 'files/Notes.pad', false, false];
		yield 'by a scan of a team folder' => [self::ROOT, '__groupfolders/3/Notes.pad', false, false];
		yield 'by a delete' => [self::HOME, 'files/Notes.pad', true, true];
		yield 'by a delete, renamed' => [self::HOME, 'files/Notes.txt', true, true];
		yield 'by a delete, on an external storage' => ['local::/mnt/share/', 'Notes.pad', true, true];
		yield 'by a delete, its versions' => [self::HOME, 'files_versions/Notes.pad.v1', true, false];
		yield 'by a delete, a preview' => [self::ROOT, 'appdata_oc123/preview/1/2/7/64-64.png', true, false];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('removals')]
	public function testWhatCounts(string $storage, string $path, bool $withinADelete, bool $counts): void {
		$listener = $this->listener();
		$node = $this->node(7, $path);

		if ($withinADelete) {
			$listener->handle(new BeforeNodeDeletedEvent($node));
		}
		$listener->handle($this->removed(7, $path, $storage));
		if ($withinADelete) {
			$listener->handle(new NodeDeletedEvent($node));
		}
		$this->endRequest();

		$this->assertSame($counts ? [['mark', [7]]] : [], $this->calls);
	}

	/**
	 * A delete counts the removals of its node and of what is under it, on
	 * the node's storage, and writes them once it is done. A removal beside
	 * the node, or on another storage, is not the delete's; one after it is
	 * a scan's again.
	 */
	public function testADeleteCountsWhatIsUnderItsNode(): void {
		$listener = $this->listener();
		$folder = $this->node(100, 'files/Sub');

		$listener->handle(new BeforeNodeDeletedEvent($folder));
		$listener->handle($this->removed(7, 'files/Sub/Notes.pad'));
		$listener->handle($this->removed(8, 'files/Sub/Deeper/Other.pad'));
		$listener->handle($this->removed(9, 'files/Subway.pad'));
		$listener->handle($this->removed(10, 'files/Sub/Elsewhere.pad', storageId: 2));
		$listener->handle($this->removed(100, 'files/Sub'));
		$listener->handle(new NodeDeletedEvent($folder));
		$this->assertSame([['mark', [7, 8, 100]]], $this->calls, 'written when the delete is done');
		$listener->handle($this->removed(11, 'files/Sub/Late.pad'));
		$this->endRequest();

		$this->assertSame([['mark', [7, 8, 100]]], $this->calls);
	}

	/**
	 * A delete that fails - a locked file - raises no NodeDeletedEvent, and
	 * its window stays open for the rest of the process: on its node's own
	 * entries only, so what a scan drops elsewhere afterwards is still no
	 * delete. A node the file cache does not have opens no window, failed
	 * or not.
	 */
	public function testAFailedDeleteLeavesNoWindowOnOtherFiles(): void {
		$listener = $this->listener();
		$locked = $this->node(100, 'files/Locked.pad');
		$uncached = $this->createMock(File::class);
		$uncached->method('getId')->willReturn(200);

		$listener->handle(new BeforeNodeDeletedEvent($locked));
		$listener->handle(new BeforeNodeDeletedEvent($uncached));
		$listener->handle($this->removed(7, 'files/Dropped.pad'));
		$listener->handle($this->removed(100, 'files/Locked.pad'));
		$this->endRequest();

		$this->assertSame([['mark', [100]]], $this->calls);
	}

	/**
	 * A delete that goes to a trash is a move: a move to a storage whose
	 * cache is wrapped copies the file into the trash under new ids, and
	 * then removes the old ones, which are not deleted for good. Removals
	 * of another delete in the same request still count, and so do those
	 * of a delete the trash did not take - vetoed, or the move failed -
	 * which puts nothing into it.
	 */
	public function testADeleteThatGoesToATrashCountsNothing(): void {
		$listener = $this->listener();
		$trashed = $this->node(100, 'Notes.pad');
		$deleted = $this->node(101, 'Other.pad');
		$refused = $this->node(102, 'Third.pad');

		$listener->handle(new BeforeNodeDeletedEvent($trashed));
		$listener->handle($this->inserted(900, 'files_trashbin/files/Notes.pad.d1'));
		$listener->handle($this->removed(100, 'Notes.pad', 'local::/mnt/share/'));
		$listener->handle(new NodeDeletedEvent($trashed));
		$listener->handle(new BeforeNodeDeletedEvent($deleted));
		$listener->handle($this->removed(101, 'Other.pad', 'local::/mnt/share/'));
		$listener->handle(new NodeDeletedEvent($deleted));
		$listener->handle(new BeforeNodeDeletedEvent($refused));
		$listener->handle($this->inserted(901, 'files/Elsewhere.pad'));
		$listener->handle($this->removed(102, 'Third.pad', 'local::/mnt/share/'));
		$listener->handle(new NodeDeletedEvent($refused));
		$this->endRequest();

		$this->assertSame([['mark', [101]], ['mark', [102]]], $this->calls);
	}

	/**
	 * A delete through a node that raises no NodeDeletedEvent - an item
	 * deleted from a trash, a trash emptied - is done at
	 * `\OCP\Files::postDelete`, and its marks are written there, not only
	 * when the process ends.
	 */
	public function testMarksAreWrittenWhenATrashDeleteIsDone(): void {
		$listener = $this->listener();

		$listener->handle($this->removed(7, 'files_trashbin/files/Notes.pad.d1'));
		$listener->handle(new GenericEvent($this->createMock(File::class)));

		$this->assertSame([['mark', [7]]], $this->calls);
	}

	/**
	 * A restore takes the file out of a trash: what it removes there is not
	 * deleted for good. Once the restore is done, a removal from the trash
	 * counts again.
	 */
	public function testARestoreCountsNothingItRemovesFromATrash(): void {
		$listener = $this->listener();
		$inTrash = $this->node(100, 'files_trashbin/files/Notes.pad.d1');
		$restored = $this->node(200, 'Notes.pad');

		$listener->handle(new BeforeNodeRestoredEvent($inTrash, $restored));
		$listener->handle($this->removed(100, 'files_trashbin/files/Notes.pad.d1'));
		// Once done, the source is no longer there, and has no id to ask.
		$listener->handle(new NodeRestoredEvent($this->goneNode('files_trashbin/files/Notes.pad.d1'), $restored));
		$listener->handle($this->removed(7, 'files_trashbin/files/Other.pad.d1'));
		// Closed by its path: a later entry there counts again.
		$listener->handle($this->removed(8, 'files_trashbin/files/Notes.pad.d1'));
		$this->endRequest();

		$this->assertSame([['mark', [7, 8]]], $this->calls);
	}

	/**
	 * A file marked, back in the file cache, then gone again, is deleted for
	 * good: the latest word stands, and nothing takes its mark back.
	 */
	public function testGoneAgainAfterComingBackStaysMarked(): void {
		$listener = $this->listener();
		$node = $this->node(7, 'files/Notes.pad');

		$listener->handle(new BeforeNodeDeletedEvent($node));
		$listener->handle($this->removed(7, 'files/Notes.pad'));
		$listener->handle(new NodeDeletedEvent($node));
		$listener->handle($this->inserted(7));
		$listener->handle($this->removed(7, 'files_trashbin/files/Notes.pad.d1'));
		$this->endRequest();

		$this->assertSame([['mark', [7]], ['mark', [7]]], $this->calls);
	}

	/**
	 * Only files with a row are kept as marked: a cleanup over millions of
	 * entries holds the handful that were pads. One without a row that comes
	 * back is none of the listener's business.
	 */
	public function testOnlyFilesWithARowAreKeptAsMarked(): void {
		$this->withoutRow = [8];
		$listener = $this->listener();
		$node = $this->node(100, 'files');

		$listener->handle(new BeforeNodeDeletedEvent($node));
		$listener->handle($this->removed(7, 'files/Notes.pad'));
		$listener->handle($this->removed(8, 'files/Photo.jpg'));
		$listener->handle(new NodeDeletedEvent($node));
		$listener->handle($this->inserted(7));
		$listener->handle($this->inserted(8));
		$this->endRequest();

		$this->assertSame([['mark', [7, 8]], ['clear', [7]]], $this->calls);
	}

	/**
	 * A move to another storage keeps the file's id, and Nextcloud reports
	 * it as a removal and an insert: the insert takes back the mark, before
	 * it is written or after.
	 */
	public function testAMoveToAnotherStorageTakesTheMarkBack(): void {
		$listener = $this->listener(block: 2);
		$node = $this->node(100, '');

		$listener->handle(new BeforeNodeDeletedEvent($node));
		$listener->handle($this->removed(7, 'Notes.pad', 'local::/mnt/share/'));
		$listener->handle($this->inserted(7));
		$listener->handle($this->removed(8, 'A.pad', 'local::/mnt/share/'));
		$listener->handle($this->removed(9, 'B.pad', 'local::/mnt/share/'));
		$listener->handle($this->inserted(9));
		$listener->handle($this->inserted(10));
		$listener->handle(new NodeDeletedEvent($node));

		$this->assertSame([['mark', [8, 9]], ['clear', [9]]], $this->calls);
	}

	/**
	 * The marks are written in blocks, each file once: Nextcloud 34.0.4
	 * reports a folder's descendants again with each block of a thousand.
	 * What is left is written when the request ends, once.
	 */
	public function testWritesInBlocksEachFileOnce(): void {
		$listener = $this->listener(block: 2);

		foreach ([1, 2, 1, 2, 3, 1] as $fileId) {
			$listener->handle($this->removed($fileId, 'files_trashbin/files/F' . $fileId . '.pad.d1'));
		}
		$this->assertSame([['mark', [1, 2]]], $this->calls);
		$this->assertCount(1, $this->atEnd, 'one write at the end of the request');
		$this->endRequest();

		$this->assertSame([['mark', [1, 2]], ['mark', [3]]], $this->calls);
	}

	/**
	 * Nextcloud 34 up to 34.0.4 reports a removed folder's descendants under
	 * their places in a block of a thousand, the block first: one holding
	 * id 0 marks none of its removals, while the folder's own removal and a
	 * block with the files' ids do.
	 */
	public function testAMisnumberedBlockDoesNotCount(): void {
		$listener = $this->listener();
		$node = $this->node(100, 'files');
		$misnumbered = [$this->removed(0, 'files/F/a.pad'), $this->removed(1, 'files/F/b.pad')];
		$numbered = [$this->removed(5, 'files/G/a.pad'), $this->removed(6, 'files/G/b.pad')];

		$listener->handle(new BeforeNodeDeletedEvent($node));
		foreach ([$misnumbered, $numbered] as $block) {
			$listener->handle($this->block($block));
			foreach ($block as $removal) {
				$listener->handle($removal);
			}
		}
		$listener->handle($this->removed(77, 'files/F'));
		$listener->handle(new NodeDeletedEvent($node));

		$this->assertSame([['mark', [5, 6, 77]]], $this->calls);
	}

	/**
	 * A user deleted marks every file on their home storage, looked up
	 * before through the mount cache and marked once the user is gone. A
	 * deletion the user backend refuses stops before that, and marks
	 * nothing; one who never logged in has no home, and no files.
	 */
	public function testAUserDeletedMarksTheirHome(): void {
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$this->mounts->method('getMountsForUser')->willReturnCallback(fn (IUser $user): array => $user === $alice
			? [$this->mount('/alice/files/Team/', 7), $this->mount('/alice/', 5)]
			: [$this->mount('/bob/files/Team/', 7)]);
		$listener = $this->listener();

		$listener->handle(new BeforeUserDeletedEvent($alice));
		$this->assertSame([['storage', [5]]], $this->calls, 'looked up, not marked');
		$listener->handle(new UserDeletedEvent($alice));
		$listener->handle(new UserDeletedEvent($alice));
		$listener->handle(new BeforeUserDeletedEvent($bob));
		$listener->handle(new UserDeletedEvent($bob));

		$this->assertSame([['storage', [5]], ['mark home', [41, 42]], ['mark home', []], ['mark home', []]], $this->calls);
	}

	/** A deletion the user backend refuses raises no UserDeletedEvent: nothing is marked. */
	public function testARefusedUserDeletionMarksNothing(): void {
		$this->mounts->method('getMountsForUser')->willReturn([$this->mount('/alice/', 5)]);
		$listener = $this->listener();

		$listener->handle(new BeforeUserDeletedEvent($this->user('alice')));
		$this->endRequest();

		$this->assertSame([['storage', [5]]], $this->calls);
	}

	/** Anything else passes by. */
	public function testAnotherEventPassesBy(): void {
		$this->listener()->handle(new Event());
		$this->endRequest();

		$this->assertSame([], $this->calls);
		$this->assertSame([], $this->atEnd);
	}

	/**
	 * A mark that fails is a warning; the delete goes ahead, and a later
	 * one in the request is heard as ever.
	 */
	public function testNothingThrows(): void {
		$this->bindings = $this->createMock(BindingService::class);
		$this->bindings->method('placeOf')->willReturn([1, 'files/Notes.pad']);
		$this->bindings->method('markIfGone')->willThrowException(new \RuntimeException('the database went away'));
		$this->logger->expects($this->exactly(2))->method('warning')->with('Could not mark the pads of files deleted for good; the consistency check lists them.', $this->anything());
		$listener = $this->listener();
		$node = $this->createMock(File::class);

		$listener->handle(new BeforeNodeDeletedEvent($node));
		$listener->handle($this->removed(7, 'files/Notes.pad'));
		$listener->handle(new NodeDeletedEvent($node));
		$listener->handle($this->removed(8, 'files_trashbin/files/Other.pad.d1'));
		$this->endRequest();
	}

	private function listener(int $block = 500): GoneFilesListener {
		$atEnd = function (\Closure $write): void {
			$this->atEnd[] = $write;
		};
		$args = [$this->bindings, $this->mounts, $this->logger];
		return $block === 500
			? new class($atEnd, ...$args) extends GoneFilesListener {
				public function __construct(private \Closure $registers, BindingService $b, IUserMountCache $m, LoggerInterface $l) {
					parent::__construct($b, $m, $l);
				}

				protected function atEnd(\Closure $write): void {
					($this->registers)($write);
				}
			}
			: new class($atEnd, ...$args) extends GoneFilesListener {
				protected const BLOCK = 2;

				public function __construct(private \Closure $registers, BindingService $b, IUserMountCache $m, LoggerInterface $l) {
					parent::__construct($b, $m, $l);
				}

				protected function atEnd(\Closure $write): void {
					($this->registers)($write);
				}
			};
	}

	private function endRequest(): void {
		foreach ($this->atEnd as $write) {
			$write();
		}
	}

	private function removed(int $fileId, string $path, string $storage = self::HOME, int $storageId = 1): CacheEntryRemovedEvent {
		return new CacheEntryRemovedEvent($this->storage($storage), $path, $fileId, $storageId);
	}

	/** A node the file cache has at $path on storage 1, alice's. */
	private function node(int $fileId, string $path): File {
		$this->places[$fileId] = [1, $path];
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($fileId);
		$node->method('getPath')->willReturn('/alice/' . $path);
		return $node;
	}

	/** A node no longer there, as a delete or restore reports it once done: no id, only its path. */
	private function goneNode(string $path): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willThrowException(new NotFoundException());
		$node->method('getPath')->willReturn('/alice/' . $path);
		return $node;
	}

	/**
	 * Nextcloud 34's CacheEntriesRemovedEvent, which carries its removals
	 * under getCacheEntryRemovedEvents().
	 *
	 * @param list<CacheEntryRemovedEvent> $removals
	 */
	private function block(array $removals): Event {
		return new class($removals) extends Event {
			/** @param list<CacheEntryRemovedEvent> $removals */
			public function __construct(private array $removals) {
			}

			/** @return list<CacheEntryRemovedEvent> */
			public function getCacheEntryRemovedEvents(): array {
				return $this->removals;
			}
		};
	}

	private function inserted(int $fileId, string $path = 'files/Moved.pad'): CacheEntryInsertedEvent {
		return new CacheEntryInsertedEvent($this->storage(self::HOME), $path, $fileId, 2);
	}

	private function storage(string $id): IStorage {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getId')->willReturn($id);
		return $storage;
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
}
