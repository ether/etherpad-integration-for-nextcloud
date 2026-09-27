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
use Psr\Log\LoggerInterface;

/**
 * A delete takes the Etherpad sessions of the protected pads it takes
 * along - a file's own, or those under a folder - to the trash as past
 * it (BeforeNodeDeletedEvent, which a move to the trash raises too). The
 * trash keeps a pad; its sessions would keep giving it to whoever holds
 * one, as the deleted file no longer does. A public pad has no sessions,
 * and stays reachable by its address.
 *
 * As on a logout (PadSessionRevoker), within a few seconds: what does not
 * fit expires on its own. Etherpad refuses the next change of whoever had
 * the pad open. Nothing here stops the delete.
 *
 * @template-implements IEventListener<Event>
 */
class RevokeSessionsOnDeleteListener implements IEventListener {
	public function __construct(
		private ProtectedPadsOfNode $protectedPads,
		private PadSessionRevoker $revoker,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeDeletedEvent) {
			return;
		}
		try {
			$pads = $this->protectedPads->of($event->getNode());
			if ($pads !== []) {
				$this->revoker->revokeForPads($pads);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not revoke the Etherpad sessions of pads leaving Files; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}
}
