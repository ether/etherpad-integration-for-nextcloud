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
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\User\Events\BeforeUserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Marks the rows of `.pad` files as they leave Files (BindingService::
 * markTrashed()): a file moved to a trash (MoveToTrashEvent), deleted past
 * the trash (BeforeNodeDeletedEvent, which a move to the trash raises too),
 * and every file of a user about to be deleted (BeforeUserDeletedEvent). A
 * file gone from the file cache after that is gone for good, however its
 * trash was emptied (GoneFileSweep).
 *
 * A delete past the trash takes the files at once, so their pads go at once
 * too: the files marked before a delete are held until Nextcloud reports it
 * done (NodeDeletedEvent, raised after a move to the trash as well), and
 * the pads of those gone from the file cache are deleted then
 * (GoneFileSweep::discardDeleted()). Nextcloud reports a folder deleted as
 * a file by then, so what was marked is what counts, not the node.
 *
 * Only a head start: the sweep marks what it finds under a trash path
 * itself, and deletes what is left. So nothing here may stop a trash or a
 * delete, and nothing throws.
 *
 * @template-implements IEventListener<Event>
 */
class LeavingPadsListener implements IEventListener {
	/** @var list<int> the files marked before a delete, until it is done */
	private array $deleting = [];

	public function __construct(
		private BindingService $bindingService,
		private GoneFileSweep $goneFileSweep,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof BeforeUserDeletedEvent) {
				$this->bindingService->markTrashedUnder($this->rootFolder->getUserFolder($event->getUser()->getUID())->getId(), wholeStorage: true);
				return;
			}
			if ($event instanceof BeforeNodeDeletedEvent) {
				$this->deleting = array_values(array_unique([...$this->deleting, ...$this->mark($event->getNode())]));
				return;
			}
			if ($event instanceof NodeDeletedEvent) {
				$deleted = $this->deleting;
				$this->deleting = [];
				$this->goneFileSweep->discardDeleted($deleted);
				return;
			}
			$this->markNodeOf($event);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not mark the pads of files leaving Files; the sweep finds them later.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}

	/** MoveToTrashEvent is the trash app's, not OCP's: its node, asked for by name. */
	private function markNodeOf(Event $event): void {
		if (!method_exists($event, 'getNode')) {
			return;
		}
		$node = $event->getNode();
		if (!$node instanceof Node) {
			return;
		}
		$this->mark($node);
	}

	/** @return list<int> the files whose rows it marked, or found marked */
	private function mark(Node $node): array {
		if ($node instanceof Folder) {
			return $this->bindingService->markTrashedUnder($node->getId());
		}
		if ($node instanceof File && PadFileType::isPad($node->getName())) {
			$this->bindingService->markTrashed($node->getId());
			return [$node->getId()];
		}
		return [];
	}
}
