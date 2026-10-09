<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * A sweep over Etherpad sessions that goes on in passes, one row per item
 * it sweeps - an author's sessions, a group's: back after a minute while
 * there is more, backed off after a failure, and stopped after three
 * delayed retries without progress. The argument holds the item's id
 * under key(), a retry's attempt, and `again` on a second look
 * (lookAgainAfter()): nothing that opens anything, since job arguments are
 * persisted and printed by occ.
 *
 * What one pass does is the subclass's (sweep()); this decides only
 * whether one pass was enough.
 */
abstract class SessionSweepJob extends QueuedJob {
	/** Seconds to wait before the next pass of a sweep that had more to do. */
	private const CONTINUE_DELAY_SECONDS = 60;

	/** Backoff after a refusal. The table is also the limit on attempts. */
	private const RETRY_DELAYS = [60, 300, 900];

	public function __construct(
		ITimeFactory $time,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/** The argument's key for the item a row sweeps. */
	abstract protected static function key(): string;

	/**
	 * One pass over $item: what it deleted, what it found and left for the
	 * next pass, whether Etherpad refused, when to look again though
	 * nothing is left, if ever, and whether the sweep is over whatever is
	 * left (`ended`): then no second look either.
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int,ended?:bool}
	 */
	abstract protected function sweep(string $item): array;

	/** What the log says when the next pass cannot be queued: what becomes of the rest. */
	abstract protected function lostPassMessage(): string;

	/**
	 * Seconds after a pass that left nothing to look once more, or null for
	 * no second look. Once: a second look that leaves nothing ends it.
	 */
	protected function lookAgainAfter(): ?int {
		return null;
	}

	/**
	 * The arguments a waiting retry can have, so whoever queues a sweep can
	 * recognise one before queueing a second row beside it.
	 *
	 * @param array<string,string> $argument
	 * @return list<array<string,string|int>>
	 */
	public static function attemptArguments(array $argument): array {
		$arguments = [];
		foreach (array_keys(self::RETRY_DELAYS) as $index) {
			$arguments[] = $argument + ['attempt' => $index + 1];
		}

		return $arguments;
	}

	/**
	 * Whether a sweep of $argument is waiting, in every shape: the job list
	 * matches arguments exactly, so asking only about the plain one would
	 * miss a retry and queue a runnable row beside it.
	 *
	 * @param array<string,string> $argument
	 */
	public static function isQueued(IJobList $jobList, array $argument): bool {
		return static::anyQueued($jobList, [$argument, ...static::attemptArguments($argument)]);
	}

	/** @param list<array<string,string|int>> $shapes */
	private static function anyQueued(IJobList $jobList, array $shapes): bool {
		foreach ($shapes as $shape) {
			if ($jobList->has(static::class, $shape)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		if (!is_array($argument)) {
			return;
		}
		$item = (string)($argument[static::key()] ?? '');
		if ($item === '') {
			return;
		}
		// A row is just data; a negative attempt would index past the table.
		$attempt = max(0, (int)($argument['attempt'] ?? 0));

		// QueuedJob removes its row before running, so during a run nothing
		// says a sweep exists, and whoever queues one - an open, a delete -
		// can queue a runnable row beside the retry that follows. It stands
		// down rather than undo the wait.
		if ($attempt === 0 && $this->retryIsWaiting($item)) {
			return;
		}

		$result = $this->sweep($item);
		if ($result['retry']) {
			// A run that deleted something is not an outage: the server
			// answered and the pile got smaller, so it waits out the delay
			// and carries on as a fresh attempt rather than being given up
			// on because a few entries were refused.
			if ($result['deleted'] > 0) {
				$this->reschedule($item, 0, self::RETRY_DELAYS[$attempt] ?? self::CONTINUE_DELAY_SECONDS);
				return;
			}

			// Nothing moved: three delayed retries without progress, then the
			// sweep stops.
			if (isset(self::RETRY_DELAYS[$attempt])) {
				$this->reschedule($item, $attempt + 1, self::RETRY_DELAYS[$attempt]);
			}
			return;
		}

		if ($result['remaining'] > 0) {
			// Queued rather than looped: holding a cron worker on a long
			// backlog would starve everything behind it.
			$this->reschedule($item, 0, self::CONTINUE_DELAY_SECONDS);
			return;
		}

		// Nothing left, so come back when the earliest session still
		// standing falls due. That row is also what tells whoever queues a
		// sweep that one is already accounted for.
		if ($result['nextDueAt'] !== null) {
			$this->reschedule($item, 0, max(1, $result['nextDueAt'] - $this->time->getTime()));
			return;
		}

		$again = $this->lookAgainAfter();
		if ($again !== null && !isset($argument['again']) && !($result['ended'] ?? false)) {
			$this->reschedule($item, 0, $again, true);
		}
	}

	/** Whether a backed-off retry for this item is waiting its turn. */
	private function retryIsWaiting(string $item): bool {
		return static::anyQueued($this->jobList, static::attemptArguments([static::key() => $item]));
	}

	/**
	 * Queue the next pass. scheduleAfter() rather than add(), which would
	 * leave the row runnable at once; and the attempt lives in the argument
	 * so a plain row queued anew is a different row and cannot reset a
	 * backoff.
	 */
	private function reschedule(string $item, int $attempt, int $delaySeconds, bool $again = false): void {
		$argument = [static::key() => $item];
		if ($attempt > 0) {
			$argument['attempt'] = $attempt;
		}
		if ($again) {
			$argument['again'] = 1;
		}

		try {
			$this->jobList->scheduleAfter(static::class, $this->time->getTime() + $delaySeconds, $argument);
		} catch (\Throwable $e) {
			// This job's row is already gone, so an unguarded throw loses the
			// rest of the backlog behind a generic job error.
			$this->logger->warning($this->lostPassMessage(), [
				'app' => 'etherpad_nextcloud',
				static::key() => $item,
				...SafeError::context($e),
			]);
		}
	}
}
