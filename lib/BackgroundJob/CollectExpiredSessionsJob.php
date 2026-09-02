<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\ExpiredSessionCollector;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/**
 * Works through one author's expired Etherpad sessions.
 *
 * Queued rather than timed, and per author rather than over everyone: the
 * backlog is noticed while a request is holding the listing anyway, so the
 * work is created exactly where it exists instead of being found again by
 * a sweep over every account on the instance.
 *
 * The author id travels in the argument. Resolving it from the uid would
 * mean reaching back into the session service, and there is no reason to:
 * whoever queued this had it in hand, and the two are one to one.
 *
 * @psalm-api
 */
class CollectExpiredSessionsJob extends QueuedJob {
	/** Seconds to wait before the next pass of a sweep that had more to do. */
	private const CONTINUE_DELAY_SECONDS = 60;

	/** Backoff after a failed listing, one entry per attempt. */
	private const RETRY_DELAYS = [60, 300, 900];
	private const MAX_RETRIES = 3;

	public function __construct(
		\OCP\AppFramework\Utility\ITimeFactory $time,
		private ExpiredSessionCollector $collector,
		private IJobList $jobList,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		if (!is_array($argument)) {
			return;
		}
		$uid = (string)($argument['uid'] ?? '');
		$authorId = (string)($argument['authorId'] ?? '');
		if ($uid === '' || $authorId === '') {
			return;
		}

		// Clamped, not trusted. The attempt travels through the jobs table,
		// and a row is just data: a negative one would index past the start
		// of the backoff table.
		$attempt = max(0, (int)($argument['attempt'] ?? 0));

		$result = $this->collector->collect($uid, $authorId);
		if ($result['retry']) {
			// The listing failed, so nothing was even attempted. This has to
			// be re-queued explicitly: QueuedJob removes its row before
			// run(), so an unreported failure is a backlog that waits for
			// somebody to open a pad and notice it all over again.
			//
			// Backed off, and given up on after a few tries. A pad server
			// that has been unreachable for half an hour will not be helped
			// by a fourth attempt, and the next open queues a fresh sweep
			// anyway.
			if ($attempt < self::MAX_RETRIES) {
				$this->reschedule($uid, $authorId, $attempt + 1, self::RETRY_DELAYS[$attempt]);
			}
			return;
		}
		if ($result['remaining'] <= 0) {
			return;
		}

		// More than one run's worth. Queued for later rather than looped
		// here: a backlog large enough to need a second pass is large
		// enough that holding a cron worker on it would starve everything
		// behind it, and nothing is waiting for this to finish.
		//
		// The attempt counter starts over: this run did its work, so the
		// next one is a continuation, not a retry.
		$this->reschedule($uid, $authorId, 0, self::CONTINUE_DELAY_SECONDS);
	}

	/**
	 * Queue the next pass.
	 *
	 * scheduleAfter() rather than add(): the delay is the whole point, and
	 * add() would leave the row runnable at once. It can still be released
	 * early — a pad open that finds the backlog again calls add() for the
	 * same class and argument, and Nextcloud updates the row rather than
	 * ignoring it. That is acceptable here and not in the collector: an
	 * early sweep is wasted work, whereas an early retry against a pad
	 * server that is still down is the loop the backoff exists to avoid.
	 * Which is why the attempt counter lives in the argument: a retry is a
	 * different row from the plain one an open queues, so an open cannot
	 * reset the backoff.
	 */
	private function reschedule(string $uid, string $authorId, int $attempt, int $delaySeconds): void {
		$argument = ['uid' => $uid, 'authorId' => $authorId];
		if ($attempt > 0) {
			$argument['attempt'] = $attempt;
		}

		$this->jobList->scheduleAfter(self::class, $this->time->getTime() + $delaySeconds, $argument);
	}
}
