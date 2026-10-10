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
 * A sweep over Etherpad sessions that goes on in passes, each a row naming
 * the item it sweeps - an author's sessions, a group's - where a first
 * pass, a retry and a second look can wait side by side: back after a
 * minute while there is more, backed off after a failure, and stopped
 * after three delayed retries without progress. The argument holds the item's id
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

	/** How long a sweep that could not read its listing waits before it asks once more (park()). */
	private const PARKED_DELAY_SECONDS = 86400;

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
	 * next pass, whether it did not get through and is worth a retry -
	 * Etherpad refusing, or not answering - when to look again though
	 * nothing is left, if ever, whether the sweep is over whatever is left
	 * (`ended`): then no second look either, and whether its listing could
	 * not be read in a run at all (`park`).
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int,ended?:bool,park?:bool}
	 */
	abstract protected function sweep(string $item): array;

	/** What the log says when the next pass cannot be queued: what becomes of the rest. */
	abstract protected function lostPassMessage(): string;

	/** What the log says when the sweep gives up after its retries: what becomes of the rest. */
	abstract protected function gaveUpMessage(): string;

	/**
	 * Whether giving up is worth a warning. A sweep of expired sessions,
	 * which an open queues anew, says it as info, or an outage would
	 * warn once for every item it met; one that leaves live access
	 * standing warns (RevokeGroupSessionsJob).
	 */
	protected function givingUpWarns(): bool {
		return false;
	}

	/**
	 * Seconds after a pass that left nothing to look once more, or null for
	 * no second look. Once: the passes a second look needs carry `again`,
	 * and the last of them that leaves nothing ends it.
	 */
	protected static function lookAgainAfter(): ?int {
		return null;
	}

	/**
	 * The arguments a waiting retry can have, so whoever queues a sweep can
	 * recognise one before queueing a second row beside it.
	 *
	 * @param array<string,string|int> $argument
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
	 * miss a retry, or a second look, and queue a runnable row beside it.
	 *
	 * @param array<string,string> $argument
	 */
	public static function isQueued(IJobList $jobList, array $argument): bool {
		return static::anyQueued($jobList, self::waitingArguments($argument));
	}

	/**
	 * Every argument a row of $argument's item can wait under: the plain
	 * one, a retry's, and both with `again` where the sweep looks twice
	 * (lookAgainAfter()). Built as reschedule() builds them, the order of
	 * the keys too, which the job list matches as well.
	 *
	 * @param array<string,string> $argument the plain one
	 * @return list<array<string,string|int>>
	 */
	private static function waitingArguments(array $argument): array {
		$looks = static::lookAgainAfter() === null ? [$argument] : [$argument, $argument + ['again' => 1]];
		$arguments = [];
		foreach ($looks as $look) {
			$arguments[] = $look;
			foreach (self::attemptArguments($look) as $retry) {
				$arguments[] = $retry;
			}
		}
		$arguments[] = $argument + ['parked' => 1];

		return $arguments;
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
		// Carried by every pass a second look needs, so it stays the one.
		$again = isset($argument['again']);

		// QueuedJob removes its row before running, so during a run nothing
		// says a sweep exists, and whoever queues one - an open, a delete -
		// can queue a runnable row beside the retry that follows. It stands
		// down rather than undo the wait.
		if ($attempt === 0 && $this->retryIsWaiting($item, $again)) {
			return;
		}

		$result = $this->sweep($item);
		if ($result['park'] ?? false) {
			$this->park($item, isset($argument['parked']));
			return;
		}
		if ($result['retry']) {
			// A run that deleted something is not an outage: the server
			// answered and the pile got smaller, so it waits out the delay
			// and carries on as a fresh attempt rather than being given up
			// on because a few entries were refused.
			if ($result['deleted'] > 0) {
				$this->reschedule($item, 0, self::RETRY_DELAYS[$attempt] ?? self::CONTINUE_DELAY_SECONDS, $again);
				return;
			}

			// Nothing moved: three delayed retries without progress, then the
			// sweep stops, and says so.
			if (!isset(self::RETRY_DELAYS[$attempt])) {
				$context = ['app' => 'etherpad_nextcloud', static::key() => $item, 'attempts' => $attempt];
				$this->givingUpWarns()
					? $this->logger->warning($this->gaveUpMessage(), $context)
					: $this->logger->info($this->gaveUpMessage(), $context);
				return;
			}
			$this->reschedule($item, $attempt + 1, self::RETRY_DELAYS[$attempt], $again);
			return;
		}

		if ($result['remaining'] > 0) {
			// Queued rather than looped: holding a cron worker on a long
			// backlog would starve everything behind it.
			$this->reschedule($item, 0, self::CONTINUE_DELAY_SECONDS, $again);
			return;
		}

		// Nothing left, so come back when the earliest session still
		// standing falls due. That row is also what tells whoever queues a
		// sweep that one is already accounted for.
		if ($result['nextDueAt'] !== null) {
			$this->reschedule($item, 0, max(1, $result['nextDueAt'] - $this->time->getTime()), $again);
			return;
		}

		if ($result['ended'] ?? false) {
			$this->dropWaitingPasses($item);
			return;
		}
		$lookAgain = static::lookAgainAfter();
		if ($lookAgain !== null && !$again) {
			$this->reschedule($item, 0, $lookAgain, true);
		}
	}

	/**
	 * A sweep whose listing no run can read now - too long, or timing out
	 * while Etherpad answers otherwise - waits a day once: asking sooner
	 * would make Etherpad walk the whole index for nothing, and the parked
	 * row keeps an open from queueing another meanwhile. Unread a second
	 * time, it ends until an open queues it again: an index no one uses
	 * costs nothing.
	 */
	private function park(string $item, bool $parkedBefore): void {
		$context = ['app' => 'etherpad_nextcloud', static::key() => $item];
		if ($parkedBefore) {
			$this->logger->info('An Etherpad session sweep still could not read its listing in a run; it ends until an open queues it again.', $context);
			return;
		}
		$this->logger->warning('An Etherpad session sweep could not read its listing in a run - too long, or too slow while Etherpad answers otherwise; it asks once more in a day.', $context);
		$this->reschedule($item, 0, self::PARKED_DELAY_SECONDS, parked: true);
	}

	/**
	 * Whether a backed-off retry this row may stand down behind is waiting
	 * its turn. A row without `again` - a delete's first pass - only behind
	 * one without it, which looks again itself once it leaves nothing: a
	 * second look's retry looks no more, and the delete would lose its own.
	 * Asked after this row is gone, so a failure to ask lets the pass run
	 * rather than lose it: a pass beside a retry costs only the pass.
	 */
	private function retryIsWaiting(string $item, bool $again): bool {
		$retries = array_values(array_filter(
			self::waitingArguments([static::key() => $item]),
			static fn (array $argument): bool => isset($argument['attempt']) && ($again || !isset($argument['again'])),
		));
		try {
			return static::anyQueued($this->jobList, $retries);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not tell whether a retry of an Etherpad session sweep is waiting; this pass runs.', [
				'app' => 'etherpad_nextcloud',
				static::key() => $item,
				...SafeError::context($e),
			]);
			return false;
		}
	}

	/**
	 * The retries and second looks of an item the sweep is over for go - a
	 * later delete's retry among them, which would only end the same way:
	 * what ended this pass holds for it too, and a delete after that
	 * queues a row of its own. Not the plain row, which a delete may queue
	 * between this pass's answer and now. One that cannot be removed runs
	 * once more.
	 */
	private function dropWaitingPasses(string $item): void {
		foreach (array_slice(self::waitingArguments([static::key() => $item]), 1) as $argument) {
			try {
				$this->jobList->remove(static::class, $argument);
			} catch (\Throwable) {
				return;
			}
		}
	}

	/**
	 * Queue the next pass. scheduleAfter() rather than add(), which would
	 * leave the row runnable at once; and the attempt lives in the argument
	 * so a plain row queued anew is a different row and cannot reset a
	 * backoff.
	 */
	private function reschedule(string $item, int $attempt, int $delaySeconds, bool $again = false, bool $parked = false): void {
		$argument = [static::key() => $item];
		if ($again) {
			$argument['again'] = 1;
		}
		if ($attempt > 0) {
			$argument['attempt'] = $attempt;
		}
		if ($parked) {
			$argument['parked'] = 1;
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
