<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedOutEvent;
use Psr\Log\LoggerInterface;

/**
 * Logging out takes the Etherpad sessions with it.
 *
 * The Etherpad session cookie outlives a Nextcloud logout: it is a
 * separate cookie on a separate host with its own lifetime, and Etherpad
 * has never been told that the person is gone.
 *
 * Every session, not only the ones this browser is carrying. Narrowing to
 * the cookie would fit the shared-machine case better — that is this
 * browser — but it would miss the case that cannot be narrowed: a cookie
 * copied off the machine, or left on one the user no longer controls. The
 * cost is that logging out on a laptop ends an open pad on a desktop —
 * and not at its next reload: Etherpad re-checks the session on every
 * socket message, so it is the next keystroke that is rejected. The same
 * mid-edit rejection that made a reused, shorter session unusable.
 *
 * Note what this cannot do: an event listener has no response, so the
 * cookie itself stays in the browser that logged out. Once the revoke has
 * gone through the ids in it are dead — the next user's author does not
 * own them — but the cookie is not cleared here, and if the revoke did not
 * get through, nothing comes back to either of them.
 *
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class UserLoggedOutListener implements IEventListener {
	public function __construct(
		private PadSessionRevoker $revoker,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserLoggedOutEvent) {
			return;
		}

		$user = $event->getUser();
		if ($user === null) {
			return;
		}

		try {
			$this->revoker->revokeAll($user->getUID());
		} catch (\Throwable $e) {
			// A logout does not fail because a pad server is unreachable.
			$this->logger->warning('Could not revoke Etherpad sessions on logout.', [
				'app' => 'etherpad_nextcloud',
				'exception' => $e,
			]);
		}
	}
}
