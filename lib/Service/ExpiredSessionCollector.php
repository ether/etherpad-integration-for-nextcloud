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
 * asking: Etherpad never removes an expired session, and
 * `listSessionsOfAuthor` walks the author's whole index one awaited lookup
 * at a time, expired entries included. Opening a protected pad asks that
 * question — it is how the app decides which of the ids in the cookie are
 * still worth carrying — so the price of an open grows with every pad the
 * user has ever opened rather than with the sessions that still grant
 * anything.
 *
 * Neither the asking nor the deleting belongs in a request. Each delete is
 * its own call, and a backlog of thousands in front of a pad appearing is
 * worse than the listing it would save; and the listing itself is what
 * gets slow. So an open leaves nothing behind but the author's id, and the
 * job does both — it finds out whether there is anything to collect, and
 * collects it.
 *
 * @psalm-api
 */
class ExpiredSessionCollector {

	/**
	 * A sweep is bounded like everything else that talks to Etherpad, but
	 * generously: nobody is waiting for it, and the whole point is to get
	 * through a backlog rather than to stay out of the way. What does not
	 * fit is picked up by the job it queues behind itself.
	 */
	private const MAX_PER_RUN = 250;

	/**
	 * How many refusals a run puts up with before it stops.
	 *
	 * Not one. A session Etherpad will never let go of — a record whose
	 * group is gone in a way that makes the delete answer with something
	 * other than "already gone" — would otherwise stand in front of every
	 * entry behind it for good: the run breaks, the next run re-lists and
	 * finds the same entry first, and the backlog never moves. Skipping
	 * past it costs one wasted call per run and reaches the other 3999.
	 *
	 * Bounded all the same, because the other reading of a refusal is that
	 * the server is down, and then there is nothing to be gained by asking
	 * two hundred more times.
	 */
	private const MAX_FAILURES_PER_RUN = 5;
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
	 * Remember that this author might have something to collect.
	 *
	 * Deliberately knows nothing about whether there is a backlog. Finding
	 * that out means the listing, and the listing is the expensive call
	 * this class exists to keep out of a request — so it happens in the
	 * job, which is also where the deleting happens and where nobody is
	 * waiting.
	 *
	 * That is the difference from asking a request to spot the backlog
	 * first: a listing is only made when a browser carries session ids, so
	 * the very first open after arriving never made one, and neither did a
	 * public link — every visitor of which writes to one shared author's
	 * index. Both were invisible to a sweep that had to be told what to
	 * look at. An author id is known on every open.
	 */
	public function noteAuthor(string $authorId): void {
		if ($authorId === '') {
			return;
		}

		// The author id and nothing else. For a public link the uid is
		// `public-share:<token>` — the bearer credential from the share
		// URL — and a job argument is persisted in the jobs table, printed
		// by occ, and carried into database dumps and support bundles. The
		// job has no use for it, and an author id maps back to a Nextcloud
		// user through their stored setting when a real one is behind it.
		$argument = ['authorId' => $authorId];
		// Both of these are Nextcloud database calls, and they run inside an
		// open somebody is waiting for. Housekeeping may not be the reason a
		// pad fails to open: a deadlock or a lost connection here would turn
		// a working open into a 500, and the worst case of swallowing it is
		// that the sweep is queued by the next open instead.
		try {
			if ($this->sweepIsQueued($argument)) {
				return;
			}
			$this->jobList->add(CollectExpiredSessionsJob::class, $argument);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not queue the Etherpad session sweep.', [
				'app' => 'etherpad_nextcloud',
				'authorId' => $authorId,
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
	 * `nextDueAt` is when the earliest session still standing becomes
	 * collectable, so a run that found nothing to do can say when it is
	 * worth coming back instead of leaving the next open to ask again. Null
	 * when the author holds nothing at all.
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int}
	 */
	public function collect(string $authorId): array {
		$deadline = microtime(true) + $this->budgetSeconds;

		try {
			// The listing is inside the budget, and until now it was the one
			// call in the run that could ignore it. It is also the expensive
			// one — the reason this class exists — so a slow index could eat
			// the whole run and leave no time to delete anything from it.
			$sessions = $this->etherpadClient->listSessionsOfAuthor(
				$authorId,
				$this->callTimeout($deadline - microtime(true)),
				$unreadable,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not list the Etherpad sessions to collect.', [
				'app' => 'etherpad_nextcloud',
				'authorId' => $authorId,
				'exception' => $e,
			]);
			return ['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null];
		}

		if (($unreadable ?? 0) > 0) {
			// Ids the author index lists that Etherpad can no longer
			// describe. `deleteSession` will not take them either — it
			// answers that they do not exist — so this run cannot shrink
			// that part of the index, and neither can any later one. It is
			// worth saying out loud rather than reporting a clean sweep:
			// the listing stays as slow as the number of keys, and on an
			// Etherpad that leaves them behind, collecting is not the
			// remedy it is here.
			$this->logger->warning('Etherpad lists sessions it cannot describe; those entries cannot be collected.', [
				'app' => 'etherpad_nextcloud',
				'authorId' => $authorId,
				'unreadableEntries' => $unreadable,
			]);
		}

		// The same grace as the counting above, for the same reason: a
		// session is only collected once both clocks must agree it is dead.
		$cutoff = time() - self::EXPIRY_GRACE_SECONDS;
		$expired = [];
		$nextDueAt = null;
		foreach ($sessions as $sessionId => $info) {
			// Anything still live is left alone. This job reclaims storage;
			// deciding that somebody's access should end is a different
			// question with different reasons behind it, and not one a
			// housekeeping sweep gets to answer.
			if ($info['validUntil'] <= $cutoff) {
				$expired[] = $sessionId;
				continue;
			}

			// Not collectable yet. Remembering when the earliest one becomes
			// so is what keeps a sweep that found nothing from being asked
			// again by the very next open: a busy public link would
			// otherwise walk one shared author's whole index behind every
			// visitor, for the length of a session lifetime.
			$dueAt = (int)$info['validUntil'] + self::EXPIRY_GRACE_SECONDS;
			$nextDueAt = $nextDueAt === null ? $dueAt : min($nextDueAt, $dueAt);
		}

		// Counted separately: `handled` is what is no longer there, whether
		// this run removed it or found it already gone, and it is the only
		// honest basis for what is left. `deleted` is what this run did, which
		// is what the log line is about.
		$handled = 0;
		$deleted = 0;
		$failures = 0;
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
				$this->etherpadClient->deleteSession($sessionId, $this->callTimeout($left));
				$deleted++;
				$handled++;
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isSessionAlreadyGone($e)) {
					$handled++;
					continue;
				}

				$failures++;
				// One line per refusal, and a handful of refusals per run.
				// Stopping at the first would let a single record Etherpad
				// will never delete stand in front of everything behind it:
				// the next run re-lists, meets the same entry first, and the
				// backlog never moves. Going past it wastes one call a run
				// and reaches the rest.
				$this->logger->warning('Could not collect an expired Etherpad session.', [
					'app' => 'etherpad_nextcloud',
					'authorId' => $authorId,
					'sessionId' => $sessionId,
					'exception' => $e,
				]);
				if ($failures >= self::MAX_FAILURES_PER_RUN) {
					// The other reading of a refusal: the server is down, and
					// two hundred more calls will not change that.
					break;
				}
			}
		}
		$remaining = count($expired) - $handled;

		if ($deleted > 0 || $remaining > 0) {
			$this->logger->debug('Collected expired Etherpad sessions.', [
				'app' => 'etherpad_nextcloud',
				'deleted' => $deleted,
				'remaining' => $remaining,
			]);
		}

		// A run that ended on a failure asks for the same backoff a failed
		// listing gets. Without it the job reads "some progress, more to do"
		// and comes back every minute for good — one call and one warning a
		// minute against a pad server that has already said no.
		return ['deleted' => $deleted, 'remaining' => $remaining, 'retry' => $failures > 0, 'nextDueAt' => $nextDueAt];
	}

	/**
	 * Whether any sweep for this author is already waiting.
	 *
	 * Every shape of it, not just the plain one. A retry carries its
	 * attempt in the argument so that an open cannot reset a backoff, and
	 * the job list matches arguments exactly — so asking only about the
	 * plain form would miss a retry that is deliberately waiting and queue
	 * a second, immediately runnable row beside it. That is the loop the
	 * backoff exists to prevent, arrived at from the other side.
	 *
	 * A handful of lookups, in a path that only runs once a backlog has
	 * built up.
	 *
	 * @param array{authorId:string} $argument
	 */
	private function sweepIsQueued(array $argument): bool {
		if ($this->jobList->has(CollectExpiredSessionsJob::class, $argument)) {
			return true;
		}
		foreach (CollectExpiredSessionsJob::attemptArguments($argument) as $retryArgument) {
			if ($this->jobList->has(CollectExpiredSessionsJob::class, $retryArgument)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What one call may take: the rest of the run's budget, but never more
	 * patience than any other request this app makes.
	 *
	 * Without the upper half a run whose listing came back quickly would
	 * hand its first delete nearly the whole budget — a housekeeping call
	 * given more time than the ones a user is waiting on, and a pad server
	 * that stalls after accepting the connection would spend the whole run
	 * on one session.
	 */
	private function callTimeout(float $left): int {
		return (int)min(floor($left), EtherpadClient::REQUEST_TIMEOUT_SECONDS);
	}
}
