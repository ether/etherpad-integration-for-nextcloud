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

	/**
	 * Backoff after a refusal, one entry per attempt. The table is the
	 * limit: a second constant saying how many there are would be a fact
	 * about this array kept somewhere else, and tuning the delays without
	 * noticing would index past its end — inside a cron worker, with this
	 * job's row already removed and the backlog with it.
	 */
	private const RETRY_DELAYS = [60, 300, 900];

	/**
	 * The arguments a waiting retry for this author can have.
	 *
	 * The collector has to recognise every one of them: the job list
	 * matches arguments exactly, so a sweep queued while a retry waits
	 * would be a second row that runs at once.
	 *
	 * @param array{uid:string,authorId:string} $argument
	 * @return list<array{uid:string,authorId:string,attempt:int}>
	 */
	public static function attemptArguments(array $argument): array {
		$arguments = [];
		foreach (array_keys(self::RETRY_DELAYS) as $index) {
			$arguments[] = $argument + ['attempt' => $index + 1];
		}

		return $arguments;
	}

	public function __construct(
		\OCP\AppFramework\Utility\ITimeFactory $time,
		private ExpiredSessionCollector $collector,
		private IJobList $jobList,
		private \Psr\Log\LoggerInterface $logger,
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
			// Something was refused — the listing, or a delete. Either way
			// this has to be re-queued explicitly: QueuedJob removes its row
			// before run(), so a failure nobody reports is a backlog waiting
			// for somebody to open a pad and notice it all over again.
			//
			// A run that deleted something is not an outage. The server
			// answered, the pile got smaller, and giving up on a sweep that
			// is visibly working would drop thousands of entries because a
			// few of them were refused. It waits out the backoff and then
			// carries on as a fresh attempt.
			if ($result['deleted'] > 0) {
				$this->reschedule($uid, $authorId, 0, self::RETRY_DELAYS[$attempt] ?? self::CONTINUE_DELAY_SECONDS);
				return;
			}

			// Nothing moved. Backed off, and given up on after a few tries:
			// a pad server unreachable for a quarter of an hour will not be
			// helped by a fourth ask, and the next open queues a fresh sweep
			// anyway.
			if (isset(self::RETRY_DELAYS[$attempt])) {
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

		try {
			$this->jobList->scheduleAfter(self::class, $this->time->getTime() + $delaySeconds, $argument);
		} catch (\Throwable $e) {
			// This job's row is already gone — QueuedJob removes it before
			// run() — so an exception here loses the rest of the backlog with
			// nothing but Nextcloud's generic job error to show for it. The
			// collector guards the same call for the same reason.
			$this->logger->warning('Could not queue the next Etherpad session sweep; the rest waits for another open.', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'authorId' => $authorId,
				'exception' => $e,
			]);
		}
	}
}
