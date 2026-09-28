<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\PadId;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
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

	/**
	 * At least every id a cookie can hold.
	 *
	 * Taking the carried sessions first only guarantees they are reached if
	 * the ceiling covers a full cookie: a lower one would revoke a prefix
	 * of what the browser is holding and leave the tail, which is the same
	 * shared-computer failure the ordering was introduced to fix. Derived
	 * rather than repeated, because two 25s in two classes are a
	 * coincidence a reader has to verify and a maintainer can break.
	 */
	private const MAX_PER_REQUEST = PadSessionService::MAX_SESSION_IDS;

	/** Below this, a call cannot finish inside the budget and is not made. */
	private const MIN_CALL_TIMEOUT_SECONDS = 1;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private PadSessionService $padSessionService,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
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
	 * Every session of the groups of the protected pads $padIds, which are
	 * leaving Files - to a trash, or past it (RevokeSessionsOnDeleteListener).
	 * A session an open made gives its pad until it expires, and the file no
	 * longer does. Bounded as a logout is: what does not fit expires on its
	 * own.
	 *
	 * Only a group that holds its pad alone, or nothing, loses its sessions:
	 * a legacy `.pad` names its own pad id, and its group may be someone
	 * else's, with other pads in it whose sessions are not this file's to
	 * end (docs/etherpad-integration.md, "Removing a pad").
	 *
	 * Each group's sessions go before the next group is asked. Listing every
	 * group first spent a slow Etherpad's budget on the listing - two calls
	 * a group - and left none for a single delete.
	 *
	 * @param list<string> $padIds
	 * @return int how many were removed
	 */
	public function revokeForPads(array $padIds): int {
		$deadline = $this->nowSeconds() + self::BUDGET_SECONDS;
		$tally = self::emptyTally();
		$groups = [];
		$unasked = 0;
		foreach ($padIds as $padId) {
			$groupId = PadId::groupIdOf($padId);
			if ($groupId === null || isset($groups[$groupId])) {
				continue;
			}
			$groups[$groupId] = true;
			if ($deadline - $this->nowSeconds() < self::MIN_CALL_TIMEOUT_SECONDS) {
				$unasked++;
				continue;
			}
			try {
				$pads = $this->etherpadClient->listPads($groupId, $this->callTimeout($deadline - $this->nowSeconds()));
				if ($pads !== [] && $pads !== [$padId]) {
					$this->logger->debug('Left the Etherpad sessions of a group that holds other pads too.', [
						'app' => 'etherpad_nextcloud',
						'groupId' => $groupId,
					]);
					continue;
				}
				if ($deadline - $this->nowSeconds() < self::MIN_CALL_TIMEOUT_SECONDS) {
					$unasked++;
					continue;
				}
				$sessions = $this->etherpadClient->listSessionsOfGroup($groupId, $this->callTimeout($deadline - $this->nowSeconds()), $unreadable);
				$tally['left'] += $unreadable ?? 0;
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
					// No group, and no session left in it.
					continue;
				}
				$this->logger->warning('Could not list the Etherpad sessions to revoke; they will expire on their own.', [
					'app' => 'etherpad_nextcloud',
					'groupId' => $groupId,
					...SafeError::context($e),
				]);
				continue;
			}
			$this->deleteLive($sessions, $deadline, [], $tally);
		}
		if ($unasked > 0) {
			$this->logger->warning('No time left to revoke Etherpad sessions; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				'groupsLeft' => $unasked,
			]);
		}
		return $this->report($tally, ['groupIds' => array_keys($groups)]);
	}

	/**
	 * Best effort throughout, and bounded. This runs from a logout listener,
	 * beside something the user asked for, so it may neither fail nor hang
	 * because a pad server is unreachable.
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
	 * bound the wrong half. Each call is given what is left of it, and one
	 * that no longer fits is not made: a deadline checked between calls
	 * would otherwise say when the last call may start, not when it must
	 * end.
	 */
	private function revoke(string $uid): int {
		$deadline = $this->nowSeconds() + self::BUDGET_SECONDS;
		$authorId = $this->padSessionService->cachedAuthorId($uid);
		if ($authorId === '') {
			// Never opened a protected pad, so nothing was ever issued.
			return 0;
		}

		// The same check every delete gets. Reading the cached author is a
		// database round trip, and if it took the budget then starting a
		// listing on top of it would overrun by a whole call — the floor
		// under callTimeout() would hand it a second it does not have.
		if ($deadline - $this->nowSeconds() < self::MIN_CALL_TIMEOUT_SECONDS) {
			$this->logger->warning('No time left to revoke Etherpad sessions; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
			]);
			return 0;
		}

		try {
			$sessions = $this->etherpadClient->listSessionsOfAuthor(
				$authorId,
				$this->callTimeout($deadline - $this->nowSeconds()),
				$unreadable,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not list the Etherpad sessions to revoke; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				...SafeError::context($e),
			]);
			return 0;
		}

		// Ids the index lists that Etherpad cannot describe. They cannot be
		// revoked — deleteSession answers that they do not exist — so they
		// belong in the number that says this logout did not finish, not
		// dropped on the way in.
		$tally = self::emptyTally();
		$tally['left'] = $unreadable ?? 0;

		$context = ['uid' => $uid];
		$this->deleteLive($this->carriedFirst($sessions), $deadline, $context, $tally);
		return $this->report($tally, $context);
	}

	/** @return array{attempted: int, revoked: int, left: int} */
	private static function emptyTally(): array {
		return ['attempted' => 0, 'revoked' => 0, 'left' => 0];
	}

	/**
	 * The live ones among $sessions deleted, within what is left of the
	 * budget until $deadline, counted into $tally: deletes attempted - one
	 * ceiling across every call for one revoke - sessions removed, and live
	 * ones left to expire. $context names whose sessions they are.
	 *
	 * @param array<string,array{groupID:string,validUntil:int}> $sessions
	 * @param array<string,mixed> $context
	 * @param array{attempted: int, revoked: int, left: int} $tally
	 */
	private function deleteLive(array $sessions, float $deadline, array $context, array &$tally): void {
		// Only what is expired on both clocks. Anything newer is treated as
		// live and revoked, which at worst deletes something already gone.
		$expiredBefore = $this->timeFactory->getTime() - EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS;
		foreach ($sessions as $sessionId => $info) {
			// An all-digit id would arrive as an int: php casts numeric
			// array keys, and everything downstream is typed string.
			$sessionId = (string)$sessionId;
			if ($info['validUntil'] <= $expiredBefore) {
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
			$left = $deadline - $this->nowSeconds();
			if ($tally['attempted'] >= self::MAX_PER_REQUEST || $left < self::MIN_CALL_TIMEOUT_SECONDS) {
				$tally['left']++;
				continue;
			}
			$tally['attempted']++;

			try {
				$this->etherpadClient->deleteSession($sessionId, $this->callTimeout($left));
				$tally['revoked']++;
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isSessionAlreadyGone($e)) {
					// Already gone, which is the outcome asked for.
					continue;
				}
				// Counted as left behind, not merely warned about: the
				// summary below is what says whether a logout finished its
				// job, and a live session the pad server refused to delete
				// is exactly as left behind as one the budget never reached.
				$tally['left']++;
				$this->logger->warning('Could not revoke an Etherpad session; it will expire on its own.', [
					'app' => 'etherpad_nextcloud',
					...$context,
					'groupId' => $info['groupID'],
					...SafeError::context($e, [$sessionId]),
				]);
			}
		}
	}

	/**
	 * The line that says how a revoke went; $context names whose sessions
	 * they were.
	 *
	 * @param array{attempted: int, revoked: int, left: int} $tally
	 * @param array<string,mixed> $context
	 * @return int how many were removed
	 */
	private function report(array $tally, array $context): int {
		$revoked = $tally['revoked'];
		$leftToExpire = $tally['left'];
		if ($revoked > 0) {
			$this->logger->info('Revoked Etherpad sessions.', [
				'app' => 'etherpad_nextcloud',
				...$context,
				'count' => $revoked,
				'leftToExpire' => $leftToExpire,
			]);
		} elseif ($leftToExpire > 0) {
			// Not "revoked" with a count of zero. That line is the shape of
			// the failure an admin would be grepping for — a logout that
			// removed nothing because the pad server was slow — and it must
			// not read like the opposite.
			$this->logger->warning('Revoked no Etherpad sessions; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				...$context,
				'leftToExpire' => $leftToExpire,
			]);
		}

		return $revoked;
	}
	/** The rest of the budget, never more than any other call in this app. */
	private function callTimeout(float $left): int {
		return (int)max(
			self::MIN_CALL_TIMEOUT_SECONDS,
			min(floor($left), EtherpadClient::REQUEST_TIMEOUT_SECONDS),
		);
	}

	/**
	 * The budget's clock, sub-second, through the same factory as the rest.
	 *
	 * `microtime(true)` here would leave the one piece of logic that
	 * actually decides an outcome on the wall clock while the seconds
	 * beside it are injected — and it is what made the budget test depend
	 * on the runner finishing a mocked call inside a second.
	 */
	private function nowSeconds(): float {
		return (float)$this->timeFactory->now()->format('U.u');
	}

	/**
	 * The same sessions, with the ones this browser is carrying first.
	 *
	 * The listing arrives in the author index's order, which is roughly the
	 * order the sessions were made — so the ceiling would spend itself on
	 * the oldest and leave the newest, and the newest is the one in the
	 * cookie of the person who just logged out. Twenty-six opens of one pad
	 * were enough to revoke twenty-five sessions and leave the only one that
	 * mattered. The set is unchanged; only the order is.
	 *
	 * @param array<string,array{groupID:string,validUntil:int}> $sessions
	 * @return array<string,array{groupID:string,validUntil:int}>
	 */
	private function carriedFirst(array $sessions): array {
		$carried = [];
		foreach ($this->padSessionService->carriedSessionIds() as $sessionId) {
			if (isset($sessions[$sessionId])) {
				$carried[$sessionId] = $sessions[$sessionId];
			}
		}

		return $carried === [] ? $sessions : $carried + $sessions;
	}

}
