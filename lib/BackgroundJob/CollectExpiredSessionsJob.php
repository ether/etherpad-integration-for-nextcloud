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
 * Queued rather than timed, and per author: an open says who might need
 * collecting, so no sweep over every account is needed. The argument
 * carries the author id and nothing else — for a public link the uid is
 * `public-share:<token>`, and job arguments are persisted.
 *
 * @psalm-api
 */
class CollectExpiredSessionsJob extends QueuedJob {
	/** Seconds to wait before the next pass of a sweep that had more to do. */
	private const CONTINUE_DELAY_SECONDS = 60;

	/** Backoff after a refusal. The table is also the limit on attempts. */
	private const RETRY_DELAYS = [60, 300, 900];

	/**
	 * The arguments a waiting retry can have, so the collector can
	 * recognise one before queueing a second row beside it.
	 *
	 * @param array{authorId:string} $argument
	 * @return list<array{authorId:string,attempt:int}>
	 */
	public static function attemptArguments(array $argument): array {
		$arguments = [];
		foreach (array_keys(self::RETRY_DELAYS) as $index) {
			$arguments[] = $argument + ['attempt' => $index + 1];
		}

		return $arguments;
	}

	/** Whether a backed-off retry for this author is waiting its turn. */
	private function retryIsWaiting(string $authorId): bool {
		foreach (self::attemptArguments(['authorId' => $authorId]) as $retryArgument) {
			if ($this->jobList->has(self::class, $retryArgument)) {
				return true;
			}
		}

		return false;
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
		$authorId = (string)($argument['authorId'] ?? '');
		if ($authorId === '') {
			return;
		}
		// A row is just data; a negative attempt would index past the table.
		$attempt = max(0, (int)($argument['attempt'] ?? 0));

		// QueuedJob removes its row before running, so during a run nothing
		// says a sweep exists and an open can queue a runnable row beside
		// the retry that follows. It stands down rather than undo the wait.
		if ($attempt === 0 && $this->retryIsWaiting($authorId)) {
			return;
		}

		$result = $this->collector->collect($authorId);
		if ($result['retry']) {
			// A run that deleted something is not an outage: the server
			// answered and the pile got smaller, so it waits out the delay
			// and carries on as a fresh attempt rather than being given up
			// on because a few entries were refused.
			if ($result['deleted'] > 0) {
				$this->reschedule($authorId, 0, self::RETRY_DELAYS[$attempt] ?? self::CONTINUE_DELAY_SECONDS);
				return;
			}

			// Nothing moved. A server unreachable for a quarter of an hour
			// will not be helped by a fourth ask, and the next open queues a
			// fresh sweep anyway.
			if (isset(self::RETRY_DELAYS[$attempt])) {
				$this->reschedule($authorId, $attempt + 1, self::RETRY_DELAYS[$attempt]);
			}
			return;
		}

		if ($result['remaining'] > 0) {
			// Queued rather than looped: holding a cron worker on a long
			// backlog would starve everything behind it.
			$this->reschedule($authorId, 0, self::CONTINUE_DELAY_SECONDS);
			return;
		}

		// Nothing left, so come back when the earliest session still
		// standing falls due. That row is also what tells an open a sweep is
		// already accounted for.
		if ($result['nextDueAt'] !== null) {
			$this->reschedule($authorId, 0, max(1, $result['nextDueAt'] - $this->time->getTime()));
		}
	}

	/**
	 * Queue the next pass. scheduleAfter() rather than add(), which would
	 * leave the row runnable at once; and the attempt lives in the argument
	 * so a plain row from an open is a different row and cannot reset a
	 * backoff.
	 */
	private function reschedule(string $authorId, int $attempt, int $delaySeconds): void {
		$argument = ['authorId' => $authorId];
		if ($attempt > 0) {
			$argument['attempt'] = $attempt;
		}

		try {
			$this->jobList->scheduleAfter(self::class, $this->time->getTime() + $delaySeconds, $argument);
		} catch (\Throwable $e) {
			// This job's row is already gone, so an unguarded throw loses the
			// rest of the backlog behind a generic job error.
			$this->logger->warning('Could not queue the next Etherpad session sweep; the rest waits for another open.', [
				'app' => 'etherpad_nextcloud',
				'authorId' => $authorId,
				'exception' => $e,
			]);
		}
	}
}
