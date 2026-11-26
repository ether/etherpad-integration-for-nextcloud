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
 * logout does (UserLoggedOutListener). Otherwise the account would keep
 * them, and with them the protected pads of other people's files shared
 * with it, until they expire.
 *
 * A delete before it happens: Nextcloud removes the account's settings,
 * the Etherpad author among them, before it says the account is gone, and
 * without the author its sessions cannot be found. A delete the user
 * backend then refuses has lost them as a logout would: the next open
 * makes new ones. A disable is heard after it, when Nextcloud reports the
 * change; the settings stay, and enabling the account again gives nothing
 * back. Only a disable through Nextcloud raises the event: an account a
 * backend blocks on its own side, in LDAP or at a SAML provider, keeps its
 * sessions until they expire.
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
