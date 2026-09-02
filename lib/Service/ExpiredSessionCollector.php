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
 * Etherpad never removes one, and `listSessionsOfAuthor` walks the whole
 * author index one awaited lookup at a time — so the cost of the listing,
 * which every protected open makes, grows with past opens rather than with
 * live access. Neither the listing nor the deleting happens in a request:
 * an open leaves the author's id, and the job does both.
 *
 * @psalm-api
 */
class ExpiredSessionCollector {

	private const MAX_PER_RUN = 250;
	private const BUDGET_SECONDS = 20.0;

	/** Refusals a run puts up with before reading them as an outage. */
	private const MAX_FAILURES_PER_RUN = 5;



	/** Below this, a call cannot finish inside the budget and is not made. */
	private const MIN_CALL_TIMEOUT_SECONDS = 2;

	/** The budget is a parameter so a test can reach it, not a setting. */
	public function __construct(
		private EtherpadClient $etherpadClient,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private float $budgetSeconds = self::BUDGET_SECONDS,
	) {
	}

	/**
	 * Remember that this author might have something to collect, without
	 * finding out whether it does — that is the listing, and the listing
	 * belongs in the job.
	 */
	public function noteAuthor(string $authorId): void {
		if ($authorId === '') {
			return;
		}

		// No uid: for a public link it is `public-share:<token>`, and a job
		// argument is persisted and printed by occ.
		$argument = ['authorId' => $authorId];
		// Housekeeping may not be the reason a pad fails to open.
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
	 * `remaining` means the run worked and did not finish; `retry` means
	 * the server refused. They must stay separate: the job removes its own
	 * row before running, so a swallowed failure loses the backlog, and a
	 * failure read as progress has the job returning every minute for good.
	 * `nextDueAt` is when the earliest session still standing becomes
	 * collectable, or null when the author holds nothing.
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int}
	 */
	public function collect(string $authorId): array {
		$deadline = microtime(true) + $this->budgetSeconds;

		try {
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
			// Keys the index lists but Etherpad cannot describe.
			// `deleteSession` will not take them either, so no run can
			// shrink that part of the index — worth saying rather than
			// reporting a clean sweep.
			$this->logger->warning('Etherpad lists sessions it cannot describe; those entries cannot be collected.', [
				'app' => 'etherpad_nextcloud',
				'authorId' => $authorId,
				'unreadableEntries' => $unreadable,
			]);
		}

		$cutoff = time() - EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS;
		$expired = [];
		$nextDueAt = null;
		foreach ($sessions as $sessionId => $info) {
			// Live sessions are left alone: ending someone's access is not a
			// housekeeping decision.
			if ($info['validUntil'] <= $cutoff) {
				$expired[] = $sessionId;
				continue;
			}

			// When the earliest becomes collectable — without it, a sweep
			// that found nothing is queued again by the very next open.
			$dueAt = (int)$info['validUntil'] + EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS;
			$nextDueAt = $nextDueAt === null ? $dueAt : min($nextDueAt, $dueAt);
		}

		// `handled` counts what is no longer there, however it went, and is
		// the only honest basis for what is left over.
		$handled = 0;
		$deleted = 0;
		$failures = 0;
		foreach ($expired as $sessionId) {
			// A deadline alone bounds when the last call starts, not when it
			// ends. One that no longer fits is not made.
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

				// Carrying on past a refusal: stopping at the first would let
				// one undeletable record shadow everything behind it for
				// good, since the next run meets it first again.
				$failures++;
				// A digest, not the id: a session id is the value of the
				// `sessionID` cookie, so it is the credential itself — and
				// this branch is reached for sessions the pad server may
				// still accept, which is why the grace above exists. The
				// digest is enough to see the same entry failing run after
				// run.
				$this->logger->warning('Could not collect an expired Etherpad session.', [
					'app' => 'etherpad_nextcloud',
					'authorId' => $authorId,
					'sessionRef' => substr(hash('sha256', $sessionId), 0, 12),
					'exception' => $e,
				]);
				if ($failures >= self::MAX_FAILURES_PER_RUN) {
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

		return ['deleted' => $deleted, 'remaining' => $remaining, 'retry' => $failures > 0, 'nextDueAt' => $nextDueAt];
	}

	/**
	 * Whether any sweep for this author is waiting — every shape of it. The
	 * job list matches arguments exactly, so asking only about the plain
	 * one would miss a retry and queue a runnable row beside it.
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
	 * The rest of the budget, capped so housekeeping is never more patient
	 * than the calls a user waits on.
	 */
	private function callTimeout(float $left): int {
		return (int)min(floor($left), EtherpadClient::REQUEST_TIMEOUT_SECONDS);
	}
}
