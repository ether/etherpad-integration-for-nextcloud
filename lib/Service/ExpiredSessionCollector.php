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
	 * How long past expiry a session has to be before this touches it.
	 *
	 * `validUntil` is a number Nextcloud computes and Etherpad judges
	 * against its own clock. If Nextcloud runs ahead, there is a window in
	 * which this side calls a session expired while the pad server still
	 * grants it — and deleting one there would close a socket somebody is
	 * typing into, which is the one thing collecting garbage must not do.
	 * Nothing here is urgent enough to be worth that, so it waits out any
	 * plausible drift first.
	 */
	private const EXPIRY_GRACE_SECONDS = 300;

	/**
	 * The collector's own request timeout, in place of the client's.
	 *
	 * A deadline checked between calls bounds when the last one starts, not
	 * when it ends: with the standard timeout a delete begun just under the
	 * deadline runs well past it, and the budget above would be a number
	 * that describes nothing. Each call gets whatever is left instead, and
	 * a call that no longer fits is not started at all — its session goes
	 * to the next run rather than over the budget.
	 */
	private const MIN_CALL_TIMEOUT_SECONDS = 2;

	/**
	 * The budget is a parameter so that the bound can be checked rather
	 * than believed. It is not a setting: nothing configures it, and the
	 * default is the only value production ever sees. A limit no test can
	 * drive is how the last one came to be off by a whole timeout.
	 */
	public function __construct(
		private EtherpadClient $etherpadClient,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private float $budgetSeconds = self::BUDGET_SECONDS,
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

		$cutoff = time() - self::EXPIRY_GRACE_SECONDS;
		$expired = 0;
		foreach ($sessions as $info) {
			if ($info['validUntil'] <= $cutoff) {
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
		// Both of these are Nextcloud database calls, and they run inside an
		// open somebody is waiting for. Housekeeping may not be the reason a
		// pad fails to open: a deadlock or a lost connection here would turn
		// a working open into a 500, and the worst case of swallowing it is
		// that the sweep is queued by the next open instead.
		try {
			if ($this->jobList->has(CollectExpiredSessionsJob::class, $argument)) {
				return;
			}
			$this->jobList->add(CollectExpiredSessionsJob::class, $argument);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not queue the Etherpad session sweep.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Delete what has expired, up to the run's budget.
	 *
	 * Three outcomes, not two. `remaining` says the sweep worked and did
	 * not finish — the ceiling, the budget, and nothing more to say than
	 * "come back". `retry` says the pad server refused, either for the
	 * listing or for a delete, and the next run should wait longer. They
	 * must not be one number in either direction: the job removes its own
	 * row before running, so a swallowed failure leaves the pile for
	 * whichever open notices it again — and a failure reported as progress
	 * has the job coming back every minute for good.
	 *
	 * @return array{deleted:int,remaining:int,retry:bool}
	 */
	public function collect(string $uid, string $authorId): array {
		$deadline = microtime(true) + $this->budgetSeconds;

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

		// The same grace as the counting above, for the same reason: a
		// session is only collected once both clocks must agree it is dead.
		$cutoff = time() - self::EXPIRY_GRACE_SECONDS;
		$expired = [];
		foreach ($sessions as $sessionId => $info) {
			// Anything still live is left alone. Taking one away is a decision
			// about permissions, and it is made in the revoker, where the
			// reason for it is known.
			if ($info['validUntil'] <= $cutoff) {
				$expired[] = $sessionId;
			}
		}

		// Counted separately: `handled` is what is no longer there, whether
		// this run removed it or found it already gone, and it is the only
		// honest basis for what is left. `deleted` is what this run did, which
		// is what the log line is about.
		$handled = 0;
		$deleted = 0;
		$failed = false;
		foreach ($expired as $sessionId) {
			// A call that cannot finish inside the budget must not start.
			// Checking only that the deadline has not passed would let the
			// last one begin with a fraction of a second left and still run
			// for its whole timeout, which is the budget being wrong by a
			// timeout again, just a smaller one.
			$left = $deadline - microtime(true);
			if ($handled >= self::MAX_PER_RUN || $left < self::MIN_CALL_TIMEOUT_SECONDS) {
				break;
			}

			try {
				$this->etherpadClient->deleteSession($sessionId, (int)floor($left));
				$deleted++;
				$handled++;
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isSessionAlreadyGone($e)) {
					$handled++;
					continue;
				}
				$failed = true;
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

		// A run that ended on a failure asks for the same backoff a failed
		// listing gets. Without it the job reads "some progress, more to do"
		// and comes back every minute for good — one call and one warning a
		// minute against a pad server that has already said no.
		return ['deleted' => $deleted, 'remaining' => $remaining, 'retry' => $failed];
	}

}
