<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\PadId;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Takes a user's Etherpad sessions away again.
 *
 * An Etherpad session is a bearer token with a lifetime: once issued, it
 * grants access to its group until `validUntil` or until it is deleted,
 * and losing the share, the file or the account does not reach it.
 *
 * No table of our own is needed for it. Sessions are issued to an Etherpad
 * author, the author for a user is cached against the uid, and Etherpad
 * will list an author's sessions on request — so a uid is enough to find
 * and remove them.
 *
 * @psalm-api
 */
class PadSessionRevoker {
	/** How much of a user-facing request this may take; the ceilings below bound its deletes. */
	private const BUDGET_SECONDS = 2.0;

	/**
	 * At least every id a cookie can hold: the carried sessions go first,
	 * and a lower ceiling would leave the tail of what the browser holds -
	 * on a shared computer, the next user's way in. Only the budget - its
	 * time, or an Etherpad refusing or not answering - ends a revoke
	 * before every carried session is tried. Derived rather than repeated,
	 * because two 25s in two classes are a coincidence a reader has to
	 * verify and a maintainer can break.
	 */
	private const MAX_PER_REQUEST = PadSessionService::MAX_SESSION_IDS;

	/**
	 * A delete's ceiling, over all the groups it takes: every open of a
	 * protected pad makes a session, so a pad opened often in six hours
	 * holds more than a cookie can. The budget bounds a delete too.
	 */
	private const MAX_PER_DELETE = 100;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private PadSessionService $padSessionService,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
		private GroupSessionRevoker $groupSessions,
		private SessionDeletes $deletes,
	) {
	}

	/**
	 * Every session this user holds, for every group: on a logout, or as an
	 * account is deleted.
	 *
	 * Every one, not only the ones this browser is carrying. A cookie that
	 * has left the machine cannot be narrowed down by the cookie you can
	 * see, and the case this exists for — a shared computer — is exactly
	 * the case where the copy you can see is not the only one.
	 *
	 * Best effort, and bounded: it runs beside something someone asked for,
	 * so it may neither fail nor hang on an unreachable pad server. A user
	 * who opened pads all morning holds a live session per open, each a
	 * call of its own; what does not fit in the budget is left to expire.
	 * The budget starts before the listing, which is a call too.
	 *
	 * @return int how many were removed
	 */
	public function revokeAll(string $uid): int {
		$budget = RunBudget::forRequest($this->timeFactory, self::BUDGET_SECONDS);
		$authorId = $this->padSessionService->cachedAuthorId($uid);
		if ($authorId === '') {
			// Never opened a protected pad, so nothing was ever issued.
			return 0;
		}

		// The same check every delete gets: reading the cached author is a
		// database round trip, and may have taken the budget.
		$timeout = $budget->nextCallTimeout();
		if ($timeout === null) {
			$this->logger->warning('No time left to revoke Etherpad sessions; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
			]);
			return 0;
		}

		try {
			$sessions = $this->etherpadClient->listSessionsOfAuthor($authorId, $timeout, $unreadable);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not list the Etherpad sessions to revoke; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				...SafeError::context($e),
			]);
			return 0;
		}

		// Ids the index lists that Etherpad cannot describe, not revoked
		// here: whether one is live cannot be told, and one with no record
		// at all deleteSession answers as not existing. They belong in the
		// number that says this logout did not finish, not dropped.
		$tally = self::emptyTally();
		$tally['left'] = $unreadable ?? 0;

		$context = ['uid' => $uid];
		$this->deleteLive($this->live($this->carriedFirst($sessions)), $budget, $context, $tally);
		return $this->report($tally, $context);
	}

	/**
	 * Every session of the groups of the protected pads $padIds, which are
	 * leaving Files - to a trash, or past it (RevokeSessionsOnDeleteListener).
	 * A session an open made gives its pad until it expires, and the file no
	 * longer does.
	 *
	 * What fits is taken here, bounded as a logout is but for the ceiling:
	 * every open makes a session, so a pad opened often holds more than a
	 * cookie can, and those that expire last go first - most often whoever
	 * is at it now. Each group's sessions go before the next group is
	 * asked, so a slow Etherpad spends the budget on deletes, not on
	 * listing. Then every group goes to a background job
	 * (GroupSessionRevoker), which takes what did not fit and what an open
	 * under way makes after the listing here.
	 *
	 * @param list<string> $padIds
	 * @return int how many were removed
	 */
	public function revokeForPads(array $padIds): int {
		$budget = RunBudget::forRequest($this->timeFactory, self::BUDGET_SECONDS);
		$tally = self::emptyTally();
		// By group, with all of its pads that leave: a legacy group whose
		// pads all leave together is left with none.
		$leaving = [];
		foreach ($padIds as $padId) {
			$groupId = PadId::groupIdOf($padId);
			if ($groupId !== null) {
				$leaving[$groupId][] = $padId;
			}
		}
		$groups = ['asked' => [], 'unasked' => 0, 'unreadable' => 0, 'handedOver' => 0];
		$expiring = 0;
		foreach ($leaving as $groupId => $groupPads) {
			$before = [$tally['left'], $groups['unasked']];
			if (!$this->takeNow($groupId, $groupPads, $budget, $tally, $groups)) {
				continue;
			}
			if ($this->groupSessions->queue($groupId)) {
				$groups['handedOver']++;
				continue;
			}
			// Not queued, which queue() says: what this group left goes to
			// no job, and expires.
			$expiring += $tally['left'] - $before[0];
			[$tally['left'], $groups['unasked']] = $before;
		}
		if ($groups['unasked'] > 0) {
			$this->logger->warning('Not every group could be asked for its Etherpad sessions to revoke; a background job takes them.', [
				'app' => 'etherpad_nextcloud',
				'groupsLeft' => $groups['unasked'],
			]);
		}
		// Every live session counted as left is in a group handed over; what
		// Etherpad lists and cannot describe is left to expire, and so is
		// what a group that could not be queued left. The groups handed over
		// are context, not something left.
		return $this->report($tally, ['groupIds' => $groups['asked'], 'groupsToTheJob' => $groups['handedOver']], [
			'leftToTheJob' => $tally['left'],
			'leftToExpire' => $groups['unreadable'] + $expiring,
		], $tally['left'] > 0);
	}

	/**
	 * What fits of one group's live sessions, taken now and counted into
	 * $tally and $groups. False for a group gone already: it holds none, and
	 * goes to no job.
	 *
	 * Past the ceiling, or with the budget spent - its time, or its patience
	 * with refusals and failures -, the group is not asked. Asked, its
	 * sessions go only when it holds nothing but the pads leaving
	 * (ManagedPadLifecycle::groupHoldsOnly(), which takes no lookup); a
	 * group holding other pads too, a legacy one, is the job's to judge.
	 *
	 * @param list<string> $groupPads
	 * @param array{attempted: int, revoked: int, left: int} $tally
	 * @param array{asked: list<string>, unasked: int, unreadable: int, handedOver: int} $groups
	 */
	private function takeNow(string $groupId, array $groupPads, RunBudget $budget, array &$tally, array &$groups): bool {
		$timeout = $tally['attempted'] < self::MAX_PER_DELETE && !$budget->exhausted() ? $budget->nextCallTimeout() : null;
		if ($timeout === null) {
			$groups['unasked']++;
			return true;
		}
		$groups['asked'][] = $groupId;
		$sessions = [];
		$unreadable = null;
		try {
			$sessions = $this->live($this->etherpadClient->listSessionsOfGroup($groupId, $timeout, $unreadable));
			// Entries this does not delete: whether one is live cannot be told.
			$groups['unreadable'] += $unreadable ?? 0;
			if ($sessions === []) {
				$budget->noteGoneThrough();
				return true;
			}
			$timeout = $budget->nextCallTimeout();
			if ($timeout === null) {
				$tally['left'] += count($sessions);
				return true;
			}
			$pads = $this->etherpadClient->listPads($groupId, $timeout);
			$budget->noteGoneThrough();
			if (!ManagedPadLifecycle::groupHoldsOnly($pads, $groupPads)) {
				$tally['left'] += count($sessions);
				$this->logger->debug('Left the Etherpad sessions of a group that holds other pads too to the background job.', [
					'app' => 'etherpad_nextcloud',
					'groupId' => $groupId,
				]);
				return true;
			}
		} catch (\Throwable $e) {
			if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				// No group, and no session left in it, nor an entry it listed:
				// gone through, as a session already gone is in a delete.
				$groups['unreadable'] -= $unreadable ?? 0;
				$budget->noteGoneThrough();
				return false;
			}
			// Those listed already are left as surely as those never listed.
			$tally['left'] += count($sessions);
			// A call without an answer counts towards an outage until a delete
			// or a group goes through (RunBudget): a few end the asking, and
			// the groups after them go to the job unasked.
			if (EtherpadClientException::isEtherpadUnreachable($e)) {
				$budget->noteUnanswered();
			}
			$this->logger->warning('Could not tell which Etherpad sessions to revoke; a background job tries again.', [
				'app' => 'etherpad_nextcloud',
				'groupId' => $groupId,
				...SafeError::context($e),
			]);
			return true;
		}
		$this->deleteLive(SessionDeletes::latestFirst($sessions), $budget, ['groupId' => $groupId], $tally, self::MAX_PER_DELETE);
		return true;
	}

	/** @return array{attempted: int, revoked: int, left: int} */
	private static function emptyTally(): array {
		return ['attempted' => 0, 'revoked' => 0, 'left' => 0];
	}

	/**
	 * The live ones among $sessions: only what is expired on both clocks is
	 * left out. Anything newer is treated as live and revoked, which at
	 * worst deletes something already gone.
	 *
	 * An expired session grants nothing already, and an author who has used
	 * protected pads for a while carries hundreds: collecting them is the
	 * background sweep's. Left out before the budget, so that what is
	 * reported as left behind is only ever a live session - the one number
	 * that says a revoke did not finish.
	 *
	 * @param array<array-key,array{groupID:string,validUntil:int}> $sessions
	 * @return array<array-key,array{groupID:string,validUntil:int}>
	 */
	private function live(array $sessions): array {
		return SessionDeletes::live($sessions, $this->timeFactory->getTime());
	}

	/**
	 * $sessions deleted in their order (SessionDeletes), within $budget and
	 * what is left of $ceiling attempts - one ceiling across every call for
	 * one revoke -, counted into $tally: attempts, sessions removed, and
	 * live ones left: to expire on a logout, to the background job on a
	 * delete. $context names whose sessions they are.
	 *
	 * @param array<array-key,array{groupID:string,validUntil:int}> $sessions
	 * @param array<string,mixed> $context
	 * @param array{attempted: int, revoked: int, left: int} $tally
	 */
	private function deleteLive(array $sessions, RunBudget $budget, array $context, array &$tally, int $ceiling = self::MAX_PER_REQUEST): void {
		$run = $this->deletes->within($budget, $sessions, $ceiling - $tally['attempted'], $context, 'Could not revoke an Etherpad session.');
		$tally['attempted'] += $run['attempted'];
		$tally['revoked'] += $run['deleted'];
		$tally['left'] += count($sessions) - $run['handled'];
	}

	/**
	 * The line that says how a revoke went; $context names whose sessions
	 * they were, $left how many sessions were left and to whom - a logout
	 * leaves them to expire - and $queued says a background job takes some.
	 * Nothing left, nothing revoked: no line.
	 *
	 * @param array{attempted: int, revoked: int, left: int} $tally
	 * @param array<string,mixed> $context
	 * @param ?array<string,int> $left
	 * @return int how many were removed
	 */
	private function report(array $tally, array $context, ?array $left = null, bool $queued = false): int {
		$revoked = $tally['revoked'];
		$left ??= ['leftToExpire' => $tally['left']];
		if ($revoked > 0) {
			$this->logger->info('Revoked Etherpad sessions.', [
				'app' => 'etherpad_nextcloud',
				...$context,
				'count' => $revoked,
				...$left,
			]);
		} elseif (array_sum($left) > 0) {
			// Not "revoked" with a count of zero. That line is the shape of
			// the failure an admin would be grepping for — a logout that
			// removed nothing because the pad server was slow — and it must
			// not read like the opposite.
			$this->logger->warning($queued
				? 'Revoked no Etherpad sessions yet; a background job takes them.'
				: 'Revoked no Etherpad sessions; they will expire on their own.', [
					'app' => 'etherpad_nextcloud',
					...$context,
					...$left,
				]);
		}

		return $revoked;
	}

	/**
	 * The same sessions, with the ones this browser is carrying first.
	 *
	 * The listing arrives roughly in the order the sessions were made, so
	 * the ceiling would spend itself on the oldest and leave the newest -
	 * the one in the cookie of the person who just logged out. The set is
	 * unchanged; only the order is.
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
