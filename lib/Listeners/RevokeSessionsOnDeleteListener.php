<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\FolderSessionRevoker;
use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCA\EtherpadNextcloud\Service\ProtectedPadsFound;
use OCA\EtherpadNextcloud\Service\ProtectedPadsOfNode;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Node;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * A delete takes the Etherpad sessions of the protected pads it takes
 * along - a file's own, or those under a folder - to the trash as past
 * it. The trash keeps a pad; its sessions would keep giving it to whoever
 * holds one, as the deleted file no longer does. A public pad has no
 * sessions, and stays reachable by its address.
 *
 * A folder's delete need not raise an event for every file in it, so the
 * pads are looked for: before the delete (BeforeNodeDeletedEvent), while
 * the folder's tree is there to walk, and their sessions go once it is
 * done (NodeDeletedEvent). A delete that fails - a file locked by a sync -
 * raises no NodeDeletedEvent and takes nothing: whoever edits the file,
 * still in Files, keeps editing.
 *
 * As on a logout (PadSessionRevoker), within a few seconds: what does not
 * fit goes to a background job per group (GroupSessionRevoker), and what
 * the walk did not reach to one that finds it (FolderSessionRevoker).
 * Etherpad refuses the next change of whoever had the pad open. Nothing
 * here stops the delete.
 *
 * @template-implements IEventListener<Event>
 */
class RevokeSessionsOnDeleteListener implements IEventListener {
	/** @var array<int,ProtectedPadsFound> by file id, what a delete under way takes along */
	private array $pending = [];

	public function __construct(
		private ProtectedPadsOfNode $protectedPads,
		private PadSessionRevoker $revoker,
		private FolderSessionRevoker $folderSessions,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof BeforeNodeDeletedEvent) {
				$node = $event->getNode();
				// A failed delete of this node may have left its entry here.
				// It goes before the walk, so a walk that finds nothing, or a
				// file's that throws, leaves nothing of it to revoke.
				unset($this->pending[$node->getId()]);
				$found = $this->walk($node);
				if ($found->pads !== [] || $found->stoppedShort) {
					$this->pending[$node->getId()] = $found;
				}
			} elseif ($event instanceof NodeDeletedEvent) {
				$fileId = $event->getNode()->getId();
				$found = $this->pending[$fileId] ?? null;
				unset($this->pending[$fileId]);
				if ($found === null) {
					return;
				}
				if ($found->stoppedShort) {
					$this->folderSessions->queue($fileId);
				}
				if ($found->pads !== []) {
					$this->revoker->revokeForPads($found->pads);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not revoke the Etherpad sessions of pads leaving Files; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}

	/** A folder's walk that fails stops short of everything: the job finds its pads by the rows, without it. */
	private function walk(Node $node): ProtectedPadsFound {
		try {
			return $this->protectedPads->of($node);
		} catch (\Throwable $e) {
			if (!$node instanceof Folder) {
				throw $e;
			}
			$this->logger->warning('Could not walk a folder being deleted for its protected pads.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $node->getId(),
				...SafeError::context($e),
			]);
			return new ProtectedPadsFound([], true);
		}
	}
}
