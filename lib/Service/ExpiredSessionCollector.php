<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredSessionsJob;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Collects the Etherpad sessions that have already expired.
 *
 * They grant nothing, so this is not about access. It is about the cost of
 * asking about access: Etherpad never removes an expired session, and
 * `listSessionsOfAuthor` walks the author's whole index one awaited lookup
 * at a time — expired entries included. So the price of a revoke grows
 * with every pad this user has ever opened, not with the number of
 * sessions that still grant anything.
 *
 * Far enough along that stops being a performance question. The revoker
 * gives a logout two seconds, and the listing is inside that budget: once
 * the listing alone can spend it, the logout revokes nothing at all and
 * the guarantee this app makes about logging out quietly stops holding.
 *
 * Which is why the collecting happens here rather than in the revoker. A
 * logout must not wait on a backlog it did not create, and deleting
 * sessions one call at a time is exactly the backlog. The request only
 * notices that there is one and leaves a note.
 *
 * @psalm-api
 */
class ExpiredSessionCollector {
	/**
	 * How many expired sessions have to pile up before a sweep is worth a
	 * row in the job table. Well under the point where the listing costs
	 * real time, and well above the handful that one day of ordinary use
	 * leaves behind.
	 */
	private const BACKLOG_THRESHOLD = 50;

	/**
	 * A sweep is bounded like everything else that talks to Etherpad, but
	 * generously: nobody is waiting for it, and the whole point is to get
	 * through a backlog rather than to stay out of the way. What does not
	 * fit is picked up by the job it queues behind itself.
	 */
	private const MAX_PER_RUN = 250;
	private const BUDGET_SECONDS = 20.0;

	/**
	 * The collector's own request timeout, in place of the client's.
	 *
	 * A deadline checked between calls bounds when the last one starts, not
	 * when it ends: with the standard timeout a delete begun just under the
	 * deadline runs well past it, and the budget above would be a number
	 * that describes nothing. Each call gets whatever is left, floored so a
	 * request is never issued with an already-dead timeout.
	 */
	private const MIN_CALL_TIMEOUT_SECONDS = 2;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Note a backlog seen in a listing somebody else already paid for.
	 *
	 * Deliberately takes the sessions rather than fetching them: every
	 * caller is a request that has the list in hand, and a collector that
	 * asked again would add a round trip to the very path it exists to
	 * keep short.
	 *
	 * @param array<string,array{groupID:string,validUntil:int}> $sessions
	 */
	public function noteBacklog(string $uid, string $authorId, array $sessions): void {
		if ($uid === '' || $authorId === '') {
			return;
		}

		$now = time();
		$expired = 0;
		foreach ($sessions as $info) {
			if ($info['validUntil'] <= $now) {
				$expired++;
			}
		}
		if ($expired < self::BACKLOG_THRESHOLD) {
			return;
		}

		$argument = ['uid' => $uid, 'authorId' => $authorId];
		// Not simply add(). Nextcloud's job list does not ignore a second
		// add for the same class and argument — it updates the row, setting
		// last_run and reserved_at back to zero and last_checked to now. A
		// job this collector deliberately scheduled a minute out would be
		// released immediately by the next pad open, which is how a backoff
		// stops being one.
		//
		// has() then add() is still not atomic, and the jobs table has no
		// unique index on (class, argument_hash), so two simultaneous opens
		// can queue two sweeps. That is waste, not damage: a sweep deletes
		// what has already expired, and a session the other run removed
		// first comes back as "already gone" and counts as handled.
		if ($this->jobList->has(CollectExpiredSessionsJob::class, $argument)) {
			return;
		}
		$this->jobList->add(CollectExpiredSessionsJob::class, $argument);
	}

	/**
	 * Delete what has expired, up to the run's budget.
	 *
	 * Three outcomes, not two. `remaining` says the sweep worked and did
	 * not finish; `retry` says it never got to start, because the listing
	 * failed. They must not be one number: the job removes its own row
	 * before running, so a run that reported nothing left would leave a
	 * pad server hiccup to be discovered by whichever open happens to
	 * notice the backlog again.
	 *
	 * @return array{deleted:int,remaining:int,retry:bool}
	 */
	public function collect(string $uid, string $authorId): array {
		$deadline = microtime(true) + self::BUDGET_SECONDS;

		try {
			$sessions = $this->etherpadClient->listSessionsOfAuthor($authorId);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not list the Etherpad sessions to collect.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'authorId' => $authorId,
				'exception' => $e,
			]);
			return ['deleted' => 0, 'remaining' => 0, 'retry' => true];
		}

		$now = time();
		$expired = [];
		foreach ($sessions as $sessionId => $info) {
			// Anything still live is left alone. Taking one away is a decision
			// about permissions, and it is made in the revoker, where the
			// reason for it is known.
			if ($info['validUntil'] <= $now) {
				$expired[] = $sessionId;
			}
		}

		// Counted separately: `handled` is what is no longer there, whether
		// this run removed it or found it already gone, and it is the only
		// honest basis for what is left. `deleted` is what this run did, which
		// is what the log line is about.
		$handled = 0;
		$deleted = 0;
		foreach ($expired as $sessionId) {
			if ($handled >= self::MAX_PER_RUN || microtime(true) >= $deadline) {
				break;
			}

			try {
				$this->etherpadClient->deleteSession($sessionId, $this->timeoutLeft($deadline));
				$deleted++;
				$handled++;
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isSessionAlreadyGone($e)) {
					$handled++;
					continue;
				}
				// One line for the run, not one per session: a pad server that
				// refuses this call refuses the next two hundred too, and the
				// queued job will come back to it anyway.
				$this->logger->warning('Could not collect an expired Etherpad session; stopping this run.', [
					'app' => 'etherpad_nextcloud',
					'uid' => $uid,
					'authorId' => $authorId,
					'exception' => $e,
				]);
				break;
			}
		}
		$remaining = count($expired) - $handled;

		if ($deleted > 0 || $remaining > 0) {
			$this->logger->debug('Collected expired Etherpad sessions.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'deleted' => $deleted,
				'remaining' => $remaining,
			]);
		}

		return ['deleted' => $deleted, 'remaining' => $remaining, 'retry' => false];
	}

	/** Whatever is left of the run's budget, never below the floor. */
	private function timeoutLeft(float $deadline): int {
		return max(self::MIN_CALL_TIMEOUT_SECONDS, (int)ceil($deadline - microtime(true)));
	}
}
