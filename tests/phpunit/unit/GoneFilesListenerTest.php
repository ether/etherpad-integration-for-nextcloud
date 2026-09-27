<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\GoneFilesListener;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCP\EventDispatcher\Event;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which removals from the file cache mark a file as deleted for good, and
 * when the marks are written: a removal from a trash, one made by a
 * delete, a user's home - never one a scan makes, and never by the name.
 */
class GoneFilesListenerTest extends TestCase {
	private const HOME = 'home::alice';
	private const ROOT = 'local::/var/www/html/data/';

	/** @var list<array{string,list<int>}> what the listener wrote, in order */
	private array $calls = [];
	/** @var list<\Closure(): void> what the request runs when it ends */
	private array $atEnd = [];
	private BindingService $bindings;
	private IUserMountCache $mounts;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->calls = [];
		$this->atEnd = [];
		$this->bindings = $this->createMock(BindingService::class);
		$this->bindings->method('markGone')->willReturnCallback(function (array $fileIds): void {
			$this->calls[] = ['mark', $fileIds];
		});
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
	 * A removal from a trash counts, whoever makes it; so does any removal
	 * a delete makes, whatever the file's name. Versions and app data never
	 * count, and a `trash/` folder counts only on a team folder's own
	 * storage. A removal outside a delete - a scan's - does not.
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
		$node = $this->createMock(File::class);

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
	 * A delete's removals are written once it is done; a delete after it
	 * whose removals do not count writes nothing, and a removal after the
	 * last delete of the request is a scan's again.
	 */
	public function testADeleteWritesWhatItRemovedWhenDone(): void {
		$listener = $this->listener();
		$node = $this->createMock(File::class);

		$listener->handle(new BeforeNodeDeletedEvent($node));
		$listener->handle($this->removed(7, 'files/Notes.pad'));
		$listener->handle(new BeforeNodeDeletedEvent($node));
		$listener->handle($this->removed(8, 'files/Sub/Other.pad'));
		$listener->handle(new NodeDeletedEvent($node));
		$this->assertSame([['mark', [7, 8]]], $this->calls, 'written when a delete is done');
		$listener->handle(new NodeDeletedEvent($node));
		$listener->handle($this->removed(9, 'files/Scanned.pad'));
		$listener->handle(new NodeDeletedEvent($node));
		$this->endRequest();

		$this->assertSame([['mark', [7, 8]]], $this->calls);
	}

	/**
	 * A move to another storage keeps the file's id, and Nextcloud reports
	 * it as a removal and an insert: the insert takes back the mark, before
	 * it is written or after.
	 */
	public function testAMoveToAnotherStorageTakesTheMarkBack(): void {
		$listener = $this->listener(block: 2);
		$node = $this->createMock(File::class);

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
		$node = $this->createMock(File::class);
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
	 * A user about to be deleted marks every file on their home storage,
	 * found through the mount cache; one who never logged in has none.
	 */
	public function testAUserDeletedMarksTheirHome(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$this->mounts->method('getMountsForUser')->willReturnOnConsecutiveCalls(
			[$this->mount('/alice/files/Team/', 7), $this->mount('/alice/', 5)],
			[$this->mount('/alice/files/Team/', 7)],
		);

		$this->listener()->handle(new BeforeUserDeletedEvent($alice));
		$this->listener()->handle(new BeforeUserDeletedEvent($alice));

		$this->assertSame([['storage', [5]], ['mark', [41, 42]], ['mark', []]], $this->calls);
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
		$this->bindings->method('markGone')->willThrowException(new \RuntimeException('the database went away'));
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

	private function removed(int $fileId, string $path, string $storage = self::HOME): CacheEntryRemovedEvent {
		return new CacheEntryRemovedEvent($this->storage($storage), $path, $fileId, 1);
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

	private function inserted(int $fileId): CacheEntryInsertedEvent {
		return new CacheEntryInsertedEvent($this->storage(self::HOME), 'files_trashbin/files/X.d1', $fileId, 2);
	}

	private function storage(string $id): IStorage {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getId')->willReturn($id);
		return $storage;
	}

	private function mount(string $mountPoint, int $storageId): ICachedMountInfo {
		$mount = $this->createMock(ICachedMountInfo::class);
		$mount->method('getMountPoint')->willReturn($mountPoint);
		$mount->method('getStorageId')->willReturn($storageId);
		return $mount;
	}
}
