<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCA\EtherpadNextcloud\Service\ProtectedPadsOfNode;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * A delete takes the Etherpad sessions of the protected pads it takes
 * along - a file's own, or those under a folder - to the trash as past
 * it. The trash keeps a pad; its sessions would keep giving it to whoever
 * holds one, as the deleted file no longer does. A public pad has no
 * sessions, and stays reachable by its address.
 *
 * The pads are found before the delete (BeforeNodeDeletedEvent), while
 * the folder's tree is there to walk, and their sessions go once it is
 * done (NodeDeletedEvent). A delete that fails - a file locked by a sync -
 * raises no NodeDeletedEvent and takes nothing: whoever edits the file,
 * still in Files, keeps editing.
 *
 * As on a logout (PadSessionRevoker), within a few seconds: what does not
 * fit expires on its own. Etherpad refuses the next change of whoever had
 * the pad open. Nothing here stops the delete.
 *
 * @template-implements IEventListener<Event>
 */
class RevokeSessionsOnDeleteListener implements IEventListener {
	/** @var array<int,list<string>> by file id, the protected pads a delete under way takes along */
	private array $pending = [];

	public function __construct(
		private ProtectedPadsOfNode $protectedPads,
		private PadSessionRevoker $revoker,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof BeforeNodeDeletedEvent) {
				$node = $event->getNode();
				$pads = $this->protectedPads->of($node);
				if ($pads !== []) {
					$this->pending[$node->getId()] = $pads;
				}
			} elseif ($event instanceof NodeDeletedEvent) {
				$fileId = $event->getNode()->getId();
				$pads = $this->pending[$fileId] ?? [];
				unset($this->pending[$fileId]);
				if ($pads !== []) {
					$this->revoker->revokeForPads($pads);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not revoke the Etherpad sessions of pads leaving Files; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}
}
