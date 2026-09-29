<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\Files\IHomeStorage;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Marks the rows of files seen deleted for good (`pending_delete`), for
 * GoneFileSweep. The file cache says what goes: Nextcloud reports each
 * entry it removes (CacheEntryRemovedEvent), each of a folder's
 * descendants too. Not every removal is a deletion - a scan drops what
 * vanished outside Nextcloud, and such a file's pad stays - so these count:
 *
 * - A removal from a trash: a user's (`files_trashbin/files/` on a home
 *   storage), a team folder's on the root storage (`__groupfolders/trash/`)
 *   or on its own storage (`trash/`). The trash emptied, an item deleted
 *   there or expired, `occ trashbin:cleanup`. A folder of one of those
 *   names on any other storage is a folder like another.
 * - A removal of a node Nextcloud deletes, or of anything under it, between
 *   the node's BeforeNodeDeletedEvent and its NodeDeletedEvent: a delete
 *   past the trash takes the node and all under it, and so does one the
 *   trash was meant for but did not take - an app vetoing it, the move
 *   failing. Where the node is - its storage and path in the file cache -
 *   is looked up as the delete starts. A delete that fails, a locked file
 *   say, raises no
 *   NodeDeletedEvent, and its window stays open for the rest of the
 *   process, a cron run's too; it covers the node's own entries only,
 *   never what a scan drops elsewhere.
 * - A user deleted: every file on their home storage, which Nextcloud
 *   clears without a word. Looked up before (BeforeUserDeletedEvent),
 *   from the home mount while the user still has one, and marked once the user
 *   is gone (UserDeletedEvent): a deletion the user backend refuses stops
 *   before that, and leaves the files as they are.
 *
 * These never count, even so:
 *
 * - A delete that goes to a trash is a move. One within a storage removes
 *   nothing; one to another storage keeps the file's id and reports a
 *   removal and an insert (CacheEntryInsertedEvent), which takes the mark
 *   back. But one to a storage whose cache is wrapped - an external
 *   storage with an encoding option, say - copies the file there under new
 *   ids first and then removes the old ones: counted, that would take the
 *   pad of a file that sits in the trash. So an insert into a trash while
 *   a node is being deleted closes the delete's window. Not
 *   MoveToTrashEvent: the trash sends it before it tries, and a delete it
 *   then does not take goes past it.
 * - A restore from a user's trash, between BeforeNodeRestoredEvent and
 *   NodeRestoredEvent: what it removes from the trash leaves it, under
 *   new ids on the other side when it crosses to such a storage.
 * - Versions on a home storage and app data on the root storage, previews
 *   among them; a file of such a name elsewhere counts as any other.
 * - What a scan drops, a file that vanished outside Nextcloud: `occ
 *   files:scan` says so (NodeRemovedFromCache) right before the entries
 *   go, and that entry and all under it are left, until its own entry
 *   goes, which Nextcloud reports after all under it. The background scan
 *   and the watcher say nothing, and what they drop in a trash counts.
 *
 * What counts is the file, not its name: a `.pad` renamed keeps its row.
 *
 * Every removal of the instance comes by here, previews and versions too,
 * so one costs a look at its path and, if it counts, a place in a set;
 * a delete costs a look at where its node is. The set is written in
 * blocks: when full, when a delete through a node is done - a trash's
 * too, which sends only `\OCP\Files::postDelete` - and at the end of the
 * process. Only files the file cache has nothing of by then are marked
 * (BindingService::markIfGone()). A team folder's trash deletes at the
 * storage, so what it removes waits for a full block or the end of the
 * process, and a process killed before loses it: those pads stay, and the
 * consistency check lists them.
 *
 * Nextcloud 34 reports a removed folder's descendants under the wrong
 * ids - their places in a block of a thousand - and again with each block
 * (fixed by nextcloud/server#63998 for 35.0.1; the backport to 34,
 * nextcloud/server#64497, is planned for 34.0.5 and not merged yet). The
 * block comes first (CacheEntriesRemovedEvent, from 34 on); one that holds
 * id 0, which no file has, is such a block, and none of its removals
 * counts. The block reaches this listener ahead of others
 * (Application::register()), so one throwing first cannot keep it away;
 * should it not come, a removal under a wrong id still marks no file the
 * file cache has - but it can mark a row whose file vanished before. The files in a folder deleted for good there keep their
 * pads, and the consistency check lists them.
 *
 * Nothing here may stop a delete, and nothing throws. What a failure
 * leaves unmarked, the consistency check lists as vanished.
 *
 * @template-implements IEventListener<Event>
 */
class GoneFilesListener implements IEventListener {
	/**
	 * Files a block marks at most.
	 *
	 * @var int
	 */
	protected const BLOCK = 500;


	/** The trash app's events, by name: the classes are not there without it. */
	private const BEFORE_RESTORE = 'OCA\\Files_Trashbin\\Events\\BeforeNodeRestoredEvent';
	private const RESTORED = 'OCA\\Files_Trashbin\\Events\\NodeRestoredEvent';

	/** What a delete through a node sends when it is done (`\OCP\Files::postDelete`), by name: the class is deprecated. */
	private const NODE_DELETED = 'OCP\\EventDispatcher\\GenericEvent';

	/** @var array<int,true> files seen deleted for good, not yet marked */
	private array $gone = [];

	/** @var array<int,true> files whose rows were marked in this request */
	private array $marked = [];

	/** @var array<int,true> files marked in this request that the file cache has again, not yet cleared */
	private array $back = [];

	/** @var array<string,list<int>> by user, the files on the home of a user about to be deleted */
	private array $leavingHomes = [];

	/** @var array<int,array{int,string,string}> by file id, the storage and path in the file cache of each node being deleted, and the node's own path: its BeforeNodeDeletedEvent without its NodeDeletedEvent yet */
	private array $deleting = [];

	/** @var array<int,array{int,string,string}> by file id, the storage and path in a trash of each node being restored, and the node's own path */
	private array $restoring = [];

	/** @var \WeakMap<object,true> removals reported under ids that are not the files' */
	private \WeakMap $misnumbered;

	private bool $writesAtEnd = false;

	/** The root storage's numeric id, once asked for. */
	private ?int $rootStorageId = null;

	/** @var array{string,string}|null the storage id and path of what a scan drops now, with all under it, until its own entry goes */
	private ?array $scanDrop = null;

	public function __construct(
		private BindingService $bindingService,
		private IMountProviderCollection $mountProviders,
		private LoggerInterface $logger,
		private IRootFolder $rootFolder,
	) {
		/** @var \WeakMap<object,true> $misnumbered */
		$misnumbered = new \WeakMap();
		$this->misnumbered = $misnumbered;
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof CacheEntryRemovedEvent) {
				$this->removed($event);
			} elseif ($event instanceof CacheEntryInsertedEvent) {
				$this->inserted($event);
			} elseif ($event instanceof BeforeNodeDeletedEvent) {
				$this->remember($this->deleting, $event->getNode());
			} elseif ($event instanceof NodeDeletedEvent) {
				self::forget($this->deleting, $event->getNode());
				$this->write();
			} elseif ($event instanceof BeforeUserDeletedEvent) {
				$this->leavingHomes[$event->getUser()->getUID()] = $this->filesOfHome($event->getUser());
			} elseif ($event instanceof UserDeletedEvent) {
				$uid = $event->getUser()->getUID();
				$fileIds = $this->leavingHomes[$uid] ?? [];
				unset($this->leavingHomes[$uid]);
				$this->bindingService->markGone($fileIds);
			} elseif ($event instanceof NodeRemovedFromCache) {
				// A scan drops a file that vanished outside Nextcloud: sent
				// right before its entries go, whatever path they are on.
				$this->scanDrop = [$event->getStorage()->getId(), $event->getPath()];
			} elseif (self::is($event, self::NODE_DELETED)) {
				$this->write();
			} elseif (self::is($event, self::BEFORE_RESTORE) && method_exists($event, 'getSource')) {
				$this->remember($this->restoring, $event->getSource());
			} elseif (self::is($event, self::RESTORED) && method_exists($event, 'getSource')) {
				self::forget($this->restoring, $event->getSource());
			} elseif (method_exists($event, 'getCacheEntryRemovedEvents')) {
				$this->block($event->getCacheEntryRemovedEvents());
			}
		} catch (\Throwable $e) {
			$this->warn($e);
		}
	}

	/**
	 * Whether the event is of the class named, or a subclass. The class may
	 * not be there: instanceof loads nothing, and answers false.
	 */
	private static function is(Event $event, string $class): bool {
		return $event instanceof $class;
	}

	/**
	 * A block of removals, which Nextcloud 34 reports before each of them.
	 *
	 * @param mixed $removals
	 */
	private function block(mixed $removals): void {
		if (!is_array($removals)) {
			return;
		}
		$events = array_values(array_filter($removals, static fn (mixed $removal): bool => $removal instanceof CacheEntryRemovedEvent));
		foreach ($events as $removal) {
			if ($removal->getFileId() === 0) {
				foreach ($events as $misnumbered) {
					$this->misnumbered[$misnumbered] = true;
				}
				return;
			}
		}
	}

	private function removed(CacheEntryRemovedEvent $event): void {
		$fileId = $event->getFileId();
		$skip = isset($this->gone[$fileId]) || isset($this->marked[$fileId]) || isset($this->misnumbered[$event]) || !$this->counts($event);
		$this->endScanDropAt($event);
		if ($skip) {
			return;
		}
		$this->gone[$fileId] = true;
		// Gone again after it came back: the latest word stands.
		unset($this->back[$fileId]);
		if (!$this->writesAtEnd) {
			$this->writesAtEnd = true;
			$this->atEnd(function (): void {
				try {
					$this->write();
				} catch (\Throwable $e) {
					$this->warn($e);
				}
			});
		}
		if (count($this->gone) >= static::BLOCK) {
			$this->write();
		}
	}

	/**
	 * A file the file cache has again: moved to another storage, not
	 * deleted. One put into a trash while a node is being deleted says the
	 * delete is a move to that trash, and every open window closes - not
	 * only that delete's: an insert does not say which delete it belongs
	 * to, and a window closed early costs a mark, the pad staying and
	 * listed as vanished, where one left open would take the pad of a file
	 * in the trash. A move to a storage whose cache is wrapped copies the
	 * file there under new ids before it removes the old ones, and
	 * counted, those would take the pad of a file that sits in the trash.
	 * A delete the trash does not take - an app vetoing it, the move
	 * failing - puts nothing there, and its removals count.
	 */
	private function inserted(CacheEntryInsertedEvent $event): void {
		if ($this->deleting !== [] && $this->inTrash($event->getPath(), $event->getStorage(), $event->getStorageId())) {
			$this->deleting = [];
		}
		$fileId = $event->getFileId();
		if (isset($this->gone[$fileId])) {
			unset($this->gone[$fileId]);
		} elseif (isset($this->marked[$fileId])) {
			unset($this->marked[$fileId]);
			$this->back[$fileId] = true;
		}
	}

	/**
	 * A window opens: where the node is - its storage and path in the file
	 * cache - by its id, with the node's own path.
	 *
	 * @param array<int,array{int,string,string}> $places
	 */
	private function remember(array &$places, mixed $node): void {
		if (!$node instanceof Node) {
			return;
		}
		$fileId = $node->getId();
		$place = $this->bindingService->placeOf($fileId);
		if ($place !== null) {
			$places[$fileId] = [$place[0], $place[1], $node->getPath()];
		}
	}

	/**
	 * A window closes, by the node's id, or by its path where the id cannot
	 * be read: the source a restore reports once it is done is no longer
	 * there. A node that answers for neither leaves it open, on the node's
	 * own entries.
	 *
	 * @param array<int,array{int,string,string}> $places
	 */
	private static function forget(array &$places, mixed $node): void {
		if (!$node instanceof Node) {
			return;
		}
		try {
			unset($places[$node->getId()]);
			return;
		} catch (NotFoundException|InvalidPathException) {
		}
		try {
			$path = $node->getPath();
		} catch (\Throwable) {
			return;
		}
		foreach ($places as $fileId => $place) {
			if ($place[2] === $path) {
				unset($places[$fileId]);
			}
		}
	}

	private function counts(CacheEntryRemovedEvent $event): bool {
		$path = $event->getPath();
		$storage = $event->getStorage();
		$storageId = $event->getStorageId();
		// Versions and app data - previews among them - never count, where
		// they are: a folder of that name elsewhere is a folder like another.
		if ((str_starts_with($path, 'files_versions/') && self::isHome($storage))
			|| (str_starts_with($path, 'appdata_') && $this->isRoot($storageId))) {
			return false;
		}
		if (self::isUnder($this->restoring, $storageId, $path) || $this->isScanDrop($storage, $path)) {
			return false;
		}
		return self::isUnder($this->deleting, $storageId, $path)
			|| $this->inTrash($path, $storage, $storageId);
	}

	/**
	 * An entry in a trash: a user's (`files_trashbin/files/` on a home
	 * storage), a team folder's on the root storage (`__groupfolders/trash/`)
	 * or on its own storage (`trash/`). A folder of that name on any other
	 * storage - an external one, say - is a folder like another.
	 */
	private function inTrash(string $path, IStorage $storage, int $storageId): bool {
		return (str_starts_with($path, BindingService::USER_TRASH_PATH . 'files/') && self::isHome($storage))
			|| (str_starts_with($path, BindingService::TEAM_TRASH_PATH) && $this->isRoot($storageId))
			|| (str_starts_with($path, 'trash/') && self::isTeamFolderStorage($storage));
	}

	/**
	 * The entry a scan drops, reported after all under it (Cache::remove()),
	 * ends its window: a removal there later in the process - a trash
	 * expiring, a delete - counts again.
	 */
	private function endScanDropAt(CacheEntryRemovedEvent $event): void {
		if ($this->scanDrop !== null && $event->getStorage()->getId() === $this->scanDrop[0] && $event->getPath() === $this->scanDrop[1]) {
			$this->scanDrop = null;
		}
	}

	/** An entry a scan is dropping: the one `occ files:scan` reported last, or one under it. */
	private function isScanDrop(IStorage $storage, string $path): bool {
		if ($this->scanDrop === null) {
			return false;
		}
		[$storageId, $root] = $this->scanDrop;
		return $storage->getId() === $storageId && ($root === '' || $path === $root || str_starts_with($path, $root . '/'));
	}

	private static function isHome(IStorage $storage): bool {
		return $storage->instanceOfStorage(IHomeStorage::class);
	}

	/** The storage Nextcloud's own data lives on: app data, and team folders without a storage of their own. */
	private function isRoot(int $storageId): bool {
		$this->rootStorageId ??= (int)$this->rootFolder->getMount('/')->getNumericStorageId();
		return $storageId === $this->rootStorageId;
	}

	/**
	 * An entry of one of these nodes: the node's own, or one under it.
	 *
	 * @param array<int,array{int,string,string}> $places
	 */
	private static function isUnder(array $places, int $storageId, string $path): bool {
		foreach ($places as [$storage, $root]) {
			if ($storage === $storageId && ($root === '' || $path === $root || str_starts_with($path, $root . '/'))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A team folder's own storage, in the data directory or an object store,
	 * whose `trash/` is the folder's trash; on any other storage it is a
	 * folder like another.
	 */
	private static function isTeamFolderStorage(IStorage $storage): bool {
		$id = $storage->getId();
		return preg_match('#^local::.*/__groupfolders/\d+/$#', $id) === 1 || str_starts_with($id, 'object::groupfolder:');
	}

	private function write(): void {
		if ($this->gone !== []) {
			$fileIds = array_keys($this->gone);
			$this->gone = [];
			// Only files with a row are kept: a cleanup over millions of
			// entries holds a handful, not all it removed.
			$this->marked += array_fill_keys($this->bindingService->markIfGone($fileIds), true);
		}
		if ($this->back !== []) {
			$fileIds = array_keys($this->back);
			$this->back = [];
			$this->bindingService->clearGone($fileIds);
		}
	}

	/** Runs $write when the request ends. */
	protected function atEnd(\Closure $write): void {
		register_shutdown_function($write);
	}

	/**
	 * The files of the rows on a user's home storage. Found as Nextcloud's
	 * own cleanup of a deleted user finds it, from the home mount its
	 * providers give, which needs no row in the mount cache. For an
	 * account that never logged in, that sets up its home storage - as
	 * that cleanup (UserDeletedFilesCleanupListener) does at the same
	 * event, so nothing is made that Nextcloud would not make anyway.
	 *
	 * @return list<int>
	 */
	private function filesOfHome(IUser $user): array {
		return $this->bindingService->fileIdsOnStorage((int)$this->mountProviders->getHomeMountForUser($user)->getNumericStorageId());
	}

	private function warn(\Throwable $e): void {
		$this->logger->warning('Could not mark the pads of files deleted for good; the consistency check lists them.', [
			'app' => 'etherpad_nextcloud',
			...SafeError::context($e),
		]);
	}
}
