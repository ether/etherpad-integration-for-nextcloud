<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use Psr\Log\LoggerInterface;

/**
 * Takes a user's Etherpad sessions away again.
 *
 * An Etherpad session is a bearer token with a lifetime: once issued, it
 * grants access to its group until `validUntil`, and nothing about losing
 * the share, the file or the account reaches it. Until this existed the
 * only thing that ever removed one was deleting its whole group.
 *
 * No table of our own is needed for it. Sessions are issued to an Etherpad
 * author, the author for a user is cached against the uid, and Etherpad
 * will list an author's sessions on request — so a uid is enough to find
 * and remove them.
 *
 * @psalm-api
 */
class PadSessionRevoker {
	/**
	 * How much of a user-facing request this may take, and how many calls
	 * it may make in it. Two numbers because either alone leaves the other
	 * unbounded: a fast pad server would run through hundreds, a slow one
	 * would spend the client timeout on the first few.
	 */
	private const BUDGET_SECONDS = 2.0;
	private const MAX_PER_REQUEST = 25;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private PadSessionService $padSessionService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every session this user holds, for every group.
	 *
	 * Every one, not only the ones this browser is carrying. A cookie that
	 * has left the machine cannot be narrowed down by the cookie you can
	 * see, and the case this exists for — a shared computer — is exactly
	 * the case where the copy you can see is not the only one.
	 *
	 * @return int how many were removed
	 */
	public function revokeAll(string $uid): int {
		return $this->revoke($uid);
	}

	/**
	 * Best effort throughout, and bounded. This runs from event listeners
	 * beside things the user asked for — a logout, an unshare — so it may
	 * neither fail nor hang because a pad server is unreachable.
	 *
	 * Bounded matters as much as best effort. Each delete is its own call
	 * with the client's full timeout behind it, and a user who has opened
	 * pads all morning holds one live session per open: a half-broken
	 * Etherpad could otherwise hold a logout for minutes. What does not fit
	 * in the budget is left to expire, which is what would have happened
	 * before any of this existed.
	 *
	 * The budget starts before the listing, because the listing is a call
	 * with the same timeout behind it and counting only the deletes would
	 * bound the wrong half. It still cannot cap the request: the check
	 * happens between calls, so one that stalls runs to the client timeout
	 * before the budget is consulted again.
	 */
	private function revoke(string $uid): int {
		$deadline = microtime(true) + self::BUDGET_SECONDS;
		$authorId = $this->padSessionService->cachedAuthorId($uid);
		if ($authorId === '') {
			// Never opened a protected pad, so nothing was ever issued.
			return 0;
		}

		try {
			$sessions = $this->etherpadClient->listSessionsOfAuthor($authorId);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not list the Etherpad sessions to revoke; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'exception' => $e,
			]);
			return 0;
		}

		// Someone has to notice the backlog, and this is the one place that
		// already holds the whole list. Leaving a note is a row in the job
		// table; working through it here would be the thing this budget
		// exists to prevent.
		$now = time();
		$attempted = 0;
		$revoked = 0;
		$skipped = 0;
		foreach ($sessions as $sessionId => $info) {
			if ($info['validUntil'] <= $now) {
				// Grants nothing already. Etherpad keeps expired sessions
				// until something deletes them, so an author who has used
				// protected pads for a while carries hundreds — and this
				// runs inside a logout the user is waiting for. Collecting
				// them is a background job's problem, not this one's.
				//
				// Checked before the budget so that what is reported as left
				// behind is only ever a live session. Counting the expired
				// tail there made the one number that says "this revoke was
				// incomplete" useless.
				continue;
			}
			// Attempts, not successes. An Etherpad that fails fast — a
			// rotated api key, a 500 — would otherwise never reach a ceiling
			// counted in completed deletes, and spend one call and one
			// warning per live session.
			if ($attempted >= self::MAX_PER_REQUEST || microtime(true) >= $deadline) {
				$skipped++;
				continue;
			}
			$attempted++;

			try {
				$this->etherpadClient->deleteSession($sessionId);
				$revoked++;
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isSessionAlreadyGone($e)) {
					// Already gone, which is the outcome asked for.
					continue;
				}
				$this->logger->warning('Could not revoke an Etherpad session; it will expire on its own.', [
					'app' => 'etherpad_nextcloud',
					'uid' => $uid,
					'groupId' => $info['groupID'],
					'exception' => $e,
				]);
			}
		}

		if ($revoked > 0 || $skipped > 0) {
			$this->logger->info('Revoked Etherpad sessions.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'count' => $revoked,
				'leftToExpire' => $skipped,
			]);
		}

		return $revoked;
	}
}
