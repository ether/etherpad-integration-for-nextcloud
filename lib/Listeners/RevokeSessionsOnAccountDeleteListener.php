<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Deleting an account takes its Etherpad sessions, as a logout does
 * (UserLoggedOutListener). An account deleted without a logout kept them,
 * and with them the protected pads of other people's files shared with
 * it, until they expired.
 *
 * Before the delete, not after: Nextcloud removes the account's settings,
 * the Etherpad author among them, before it says the account is gone, and
 * without the author its sessions cannot be found. A delete the user
 * backend then refuses has lost them as a logout would: the next open
 * makes new ones.
 *
 * @template-implements IEventListener<Event>
 */
class RevokeSessionsOnAccountDeleteListener implements IEventListener {
	public function __construct(
		private PadSessionRevoker $revoker,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeUserDeletedEvent) {
			return;
		}

		try {
			$this->revoker->revokeAll($event->getUser()->getUID());
		} catch (\Throwable $e) {
			// An account is deleted whatever the pad server says.
			$this->logger->warning('Could not revoke the Etherpad sessions of an account being deleted; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}
}
