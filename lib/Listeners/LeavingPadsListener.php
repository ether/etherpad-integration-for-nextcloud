<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\GoneFileSweep;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use OCP\User\Events\BeforeUserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Keeps the marks of `.pad` files leaving Files (Binding::$trashedAt), for
 * the sweep of files gone for good (GoneFileSweep):
 *
 * - A move to a trash (MoveToTrashEvent) marks the file, or every file
 *   under a folder: once gone from the file cache after that, a file is
 *   gone for good, however its trash was emptied.
 * - A delete past the trash takes the files at once, so their pads go at
 *   once too. Before it (BeforeNodeDeletedEvent, which a move to the trash
 *   raises too, first) the files it takes along are only looked up; a move
 *   to the trash then marks those, and once the delete is done
 *   (NodeDeletedEvent) the ones gone from the file cache - all of a delete
 *   past the trash, none of a move to it - are marked and deleted
 *   (GoneFileSweep::discardDeleted()). Nextcloud reports a folder as a
 *   file after the delete, so what counts is what was looked up before.
 * - A user about to be deleted (BeforeUserDeletedEvent): every file on
 *   their home storage, found through the mount cache rather than a home
 *   set up just to be deleted.
 * - A restore clears the marks it brings back: NodeRestoredEvent, and the
 *   legacy hook (TrashbinHookHandler), which groupfolders alone raises.
 *   Core raises both, the hook first; the event then clears nothing again.
 *
 * Nothing here may stop a trash, a delete or a restore, and nothing throws:
 * the sweep's own pass marks what it finds in a trash and clears what it
 * finds back in Files.
 *
 * @template-implements IEventListener<Event>
 */
class LeavingPadsListener implements IEventListener {
	/** @var array<int,list<int>> by the id of a node about to be deleted, the files it takes along */
	private array $deleting = [];

	/** @var array<int,true> the nodes whose restore this request has cleared: core raises the hook and the event */
	private array $restored = [];

	public function __construct(
		private BindingService $bindingService,
		private GoneFileSweep $goneFileSweep,
		private IRootFolder $rootFolder,
		private IUserMountCache $userMountCache,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof BeforeUserDeletedEvent) {
				$this->bindingService->markTrashed($this->filesOfHome($event->getUser()));
			} elseif ($event instanceof BeforeNodeDeletedEvent) {
				$node = $event->getNode();
				$this->deleting[$node->getId()] = $this->padsOf($node);
			} elseif ($event instanceof NodeDeletedEvent) {
				$deleted = array_values(array_unique(array_merge([], ...array_values($this->deleting))));
				$this->deleting = [];
				$this->goneFileSweep->discardDeleted($deleted);
			} elseif (method_exists($event, 'getTarget')) {
				$this->restored($event->getTarget());
			} else {
				$this->trashed($event);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not keep the marks of pads leaving Files; the sweep finds them later.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * The legacy restore hook's path, relative to the files of the user who
	 * restores: groupfolders raises no NodeRestoredEvent. Never throws.
	 */
	public function restoredPath(string $path): void {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return;
			}
			$this->restored($this->rootFolder->getUserFolder($user->getUID())->get($path));
		} catch (\Throwable $e) {
			$this->logger->warning('Could not keep the marks of pads leaving Files; the sweep finds them later.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * MoveToTrashEvent is the trash app's, not OCP's: its node, asked for by
	 * name. What the delete before it looked up is marked, rather than
	 * looked up again, and kept for its end: a move to the trash that fails
	 * falls back to a delete past it, whose files go at once too.
	 */
	private function trashed(Event $event): void {
		if (!method_exists($event, 'getNode')) {
			return;
		}
		$node = $event->getNode();
		if (!$node instanceof Node) {
			return;
		}
		$this->bindingService->markTrashed($this->deleting[$node->getId()] ?? $this->padsOf($node));
	}

	/**
	 * A restored node, looked up again by its path when it cannot give its
	 * id: on Nextcloud 31 the event's node is not resolvable yet.
	 */
	private function restored(mixed $node): void {
		if (!$node instanceof Node) {
			return;
		}
		try {
			$id = $node->getId();
		} catch (\Throwable) {
			$node = $this->rootFolder->get($node->getPath());
			$id = $node->getId();
		}
		if (isset($this->restored[$id])) {
			return;
		}
		$this->restored[$id] = true;
		$this->bindingService->clearTrashed($this->padsOf($node));
	}

	/** @return list<int> the files of the rows a node takes along */
	private function padsOf(Node $node): array {
		if ($node instanceof Folder) {
			return $this->bindingService->fileIdsUnder($node->getId());
		}
		if ($node instanceof File && PadFileType::isPad($node->getName())) {
			return [$node->getId()];
		}
		return [];
	}

	/** @return list<int> the files of the rows on a user's home storage */
	private function filesOfHome(IUser $user): array {
		$home = '/' . $user->getUID() . '/';
		foreach ($this->userMountCache->getMountsForUser($user) as $mount) {
			if ($mount->getMountPoint() === $home) {
				return $this->bindingService->fileIdsOnStorage($mount->getStorageId());
			}
		}
		return [];
	}
}
