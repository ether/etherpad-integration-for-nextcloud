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
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;

/**
 * Deleting or disabling an account takes its Etherpad sessions, as a
 * logout does (UserLoggedOutListener); otherwise they would keep the
 * protected pads shared with it open until they expire. A delete is heard
 * before it happens, while Nextcloud still has the account's Etherpad
 * author; a disable after it. A block an LDAP or SAML backend makes on its
 * own side raises no event (docs/sessions.md).
 *
 * @template-implements IEventListener<Event>
 */
class RevokeSessionsOnAccountDeleteOrDisableListener implements IEventListener {
	public function __construct(
		private PadSessionRevoker $revoker,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof BeforeUserDeletedEvent) {
			$this->revoke($event->getUser()->getUID(), 'Could not revoke the Etherpad sessions of an account being deleted; they will expire on their own.');
		} elseif ($event instanceof UserChangedEvent && $event->getFeature() === 'enabled' && $event->getValue() === false) {
			$this->revoke($event->getUser()->getUID(), 'Could not revoke the Etherpad sessions of a disabled account; they will expire on their own.');
		}
	}

	private function revoke(string $uid, string $failure): void {
		try {
			$this->revoker->revokeAll($uid);
		} catch (\Throwable $e) {
			// An account is deleted or disabled whatever the pad server says.
			$this->logger->warning($failure, [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}
}
