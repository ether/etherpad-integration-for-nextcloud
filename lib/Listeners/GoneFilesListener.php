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
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\InvalidPathException;
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
 * - A removal from a trash: a user's (`files_trashbin/files/`), a team
 *   folder's on the root storage (`__groupfolders/trash/`) or on its own
 *   storage (`trash/`). The trash emptied, an item deleted there or
 *   expired, `occ trashbin:cleanup`.
 * - A removal of a node Nextcloud deletes, or of anything under it, between
 *   the node's BeforeNodeDeletedEvent and its NodeDeletedEvent: a delete
 *   past the trash takes the node and all under it. Where the node is -
 *   its storage and path in the file cache - is looked up as the delete
 *   starts. A delete that fails, a locked file say, raises no
 *   NodeDeletedEvent, and its window stays open for the rest of the
 *   process, a cron run's too; it covers the node's own entries only,
 *   never what a scan drops elsewhere. A move to the trash within one
 *   storage removes nothing. One
 *   to another storage keeps the file's id and reports a removal and an
 *   insert (CacheEntryInsertedEvent), which takes the mark back.
 * - A user deleted: every file on their home storage, which Nextcloud
 *   clears without a word. Looked up before (BeforeUserDeletedEvent),
 *   while the mount cache still knows the home, and marked once the user
 *   is gone (UserDeletedEvent): a deletion the user backend refuses stops
 *   before that, and leaves the files as they are.
 *
 * What counts is the file, not its name: a `.pad` renamed keeps its row.
 *
 * Every removal of the instance comes by here, previews and versions too,
 * so one costs a look at its path and, if it counts, a place in a set;
 * a delete costs a look at where its node is. The set is written in
 * blocks: when full, when a delete is done, and at the end of the
 * request. Only files the file cache has nothing of by then are marked
 * (BindingService::markIfGone()).
 *
 * Nextcloud 34 reports a removed folder's descendants under the wrong
 * ids - their places in a block of a thousand - and again with each block
 * (fixed by nextcloud/server#63998 for 35.0.1; the backport to 34,
 * nextcloud/server#64497, is planned for 34.0.5 and not merged yet). The
 * block comes first (CacheEntriesRemovedEvent, from 34 on); one that holds
 * id 0, which no file has, is such a block, and none of its removals
 * counts. Should the block not reach this listener - another listener
 * throwing first - a removal under a wrong id still marks no file the
 * file cache has. The files in a folder deleted for good there keep their
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

	/** Removals that never count, however they come: versions and app data, previews among them. */
	private const NEVER = ['files_versions/', 'appdata_'];

	/** @var array<int,true> files seen deleted for good, not yet marked */
	private array $gone = [];

	/** @var array<int,true> files marked in this request */
	private array $marked = [];

	/** @var array<int,true> files marked in this request that the file cache has again, not yet cleared */
	private array $back = [];

	/** @var array<string,list<int>> by user, the files on the home of a user about to be deleted */
	private array $leavingHomes = [];

	/** @var array<int,array{int,string}> by file id, the storage and path of each node being deleted: its BeforeNodeDeletedEvent without its NodeDeletedEvent yet */
	private array $deleting = [];

	/** @var \WeakMap<object,true> removals reported under ids that are not the files' */
	private \WeakMap $misnumbered;

	private bool $writesAtEnd = false;

	public function __construct(
		private BindingService $bindingService,
		private IUserMountCache $userMountCache,
		private LoggerInterface $logger,
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
				$this->inserted($event->getFileId());
			} elseif ($event instanceof BeforeNodeDeletedEvent) {
				$this->startDelete($event->getNode());
			} elseif ($event instanceof NodeDeletedEvent) {
				$this->endDelete($event->getNode());
				$this->write();
			} elseif ($event instanceof BeforeUserDeletedEvent) {
				$this->leavingHomes[$event->getUser()->getUID()] = $this->filesOfHome($event->getUser());
			} elseif ($event instanceof UserDeletedEvent) {
				$uid = $event->getUser()->getUID();
				$fileIds = $this->leavingHomes[$uid] ?? [];
				unset($this->leavingHomes[$uid]);
				$this->bindingService->markGone($fileIds);
			} elseif (method_exists($event, 'getCacheEntryRemovedEvents')) {
				$this->block($event->getCacheEntryRemovedEvents());
			}
		} catch (\Throwable $e) {
			$this->warn($e);
		}
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
		if (isset($this->gone[$fileId]) || isset($this->marked[$fileId]) || isset($this->misnumbered[$event]) || !$this->counts($event)) {
			return;
		}
		$this->gone[$fileId] = true;
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

	/** A file the file cache has again: moved to another storage, not deleted. */
	private function inserted(int $fileId): void {
		if (isset($this->gone[$fileId])) {
			unset($this->gone[$fileId]);
		} elseif (isset($this->marked[$fileId])) {
			unset($this->marked[$fileId]);
			$this->back[$fileId] = true;
		}
	}

	private function startDelete(Node $node): void {
		$fileId = $node->getId();
		$place = $this->bindingService->placeOf($fileId);
		if ($place !== null) {
			$this->deleting[$fileId] = $place;
		}
	}

	private function endDelete(Node $node): void {
		try {
			unset($this->deleting[$node->getId()]);
		} catch (NotFoundException|InvalidPathException) {
			// Its window stays open, on the node's own entries.
		}
	}

	private function counts(CacheEntryRemovedEvent $event): bool {
		$path = $event->getPath();
		foreach (self::NEVER as $prefix) {
			if (str_starts_with($path, $prefix)) {
				return false;
			}
		}
		return $this->isBeingDeleted($event->getStorageId(), $path)
			|| str_starts_with($path, BindingService::USER_TRASH_PATH . 'files/')
			|| str_starts_with($path, BindingService::TEAM_TRASH_PATH)
			|| (str_starts_with($path, 'trash/') && self::isTeamFolderStorage($event->getStorage()));
	}

	/** An entry of a node being deleted: the node's own, or one under it. */
	private function isBeingDeleted(int $storageId, string $path): bool {
		foreach ($this->deleting as [$storage, $root]) {
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
			$this->marked += array_fill_keys($fileIds, true);
			$this->bindingService->markIfGone($fileIds);
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

	/** @return list<int> the files of the rows on a user's home storage */
	private function filesOfHome(IUser $user): array {
		$home = '/' . $user->getUID() . '/';
		foreach ($this->userMountCache->getMountsForUser($user) as $mount) {
			if ($mount->getMountPoint() === $home) {
				return $this->bindingService->fileIdsOnStorage($mount->getStorageId());
			}
		}
		// Never logged in: no home, and no files.
		return [];
	}

	private function warn(\Throwable $e): void {
		$this->logger->warning('Could not mark the pads of files deleted for good; the consistency check lists them.', [
			'app' => 'etherpad_nextcloud',
			...SafeError::context($e),
		]);
	}
}
