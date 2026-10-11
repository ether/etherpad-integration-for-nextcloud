<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredGroupSessionsJob;
use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredSessionsJob;
use OCA\EtherpadNextcloud\BackgroundJob\SessionSweepJob;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Collects the Etherpad sessions that have already expired, which
 * Etherpad never removes. A listing costs a lookup per session in the
 * index, so no request collects: an open notes its author (noteAuthor()),
 * a public link's its group too (noteGroup()) - a visitor's own author
 * only that, one job a group however many visit - and a job lists the
 * expired ones and deletes them.
 *
 * A group's sweep takes every author's expired sessions in it, which keeps
 * short the listing a delete's revoke reads. The link's own author is
 * swept beside it, as a group can hold more than a run can list in time;
 * a visitor's author has no such fallback.
 */
class ExpiredSessionCollector {

	private const MAX_PER_RUN = 250;

	/**
	 * The least a group's sweep waits before it comes back for a session
	 * still live: while anyone uses the pad, the earliest of everyone's
	 * sessions is never far off, and each pass lists the whole group. A
	 * backlog is still worked off a pass a minute.
	 */
	private const GROUP_SWEEP_INTERVAL_SECONDS = 3600;

	/** The budget is a parameter so a test can reach it, not a setting. */
	public function __construct(
		private EtherpadClient $etherpadClient,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
		private SessionDeletes $deletes,
		private ManagedPadLifecycle $padLifecycle,
		private float $budgetSeconds = RunBudget::DEFAULT_SECONDS,
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

		// The author's id, not a uid: a job argument is persisted and
		// printed by occ.
		$this->note(CollectExpiredSessionsJob::class, ['authorId' => $authorId]);
	}

	/** noteAuthor() for the group a public link opened. */
	public function noteGroup(string $groupId): void {
		if ($groupId === '') {
			return;
		}

		$this->note(CollectExpiredGroupSessionsJob::class, ['groupId' => $groupId]);
	}

	/**
	 * @param class-string<SessionSweepJob> $job
	 * @param array<string,string> $argument
	 */
	private function note(string $job, array $argument): void {
		// Housekeeping may not be the reason a pad fails to open.
		try {
			if ($job::isQueued($this->jobList, $argument)) {
				return;
			}
			$this->jobList->add($job, $argument);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not queue the Etherpad session sweep.', [
				'app' => 'etherpad_nextcloud',
				...$argument,
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * Delete what has expired, up to the run's budget.
	 *
	 * `remaining` means the run worked and did not finish; `retry` means it
	 * did not get through and is worth another try - a listing in an
	 * outage, refused, or failed with an HTTP error or a broken answer, a
	 * delete refused. They must stay separate: the job removes its own row
	 * before running, so a swallowed failure loses the backlog, and a
	 * failure read as progress has the job returning every minute for good.
	 * `nextDueAt` is when to come back though nothing is left to delete:
	 * when the earliest live session becomes collectable, or null when
	 * nothing live is left. `park` says why the listing cannot be read in a
	 * run: too long, or timing out while Etherpad answers otherwise. An
	 * author Etherpad does not know holds nothing, and the sweep ends.
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int,park?:'tooLong'|'timeout'}
	 */
	public function collect(string $authorId): array {
		return $this->collectFor(['authorId' => $authorId], function (RunBudget $budget) use ($authorId): array {
			$sessions = $this->etherpadClient->listSessionsOfAuthor($authorId, $budget->callTimeout(), $unreadable, EtherpadClient::SESSION_LISTING_MAX_BYTES);
			return [$sessions, $unreadable];
		}, EtherpadErrorClassifier::isAuthorUnknown(...));
	}

	/**
	 * collect() for a group's sessions, every author's: a public link's
	 * (noteGroup()). A group Etherpad no longer has holds none, and the
	 * sweep ends; one with sessions still live comes back for them an hour
	 * on at the soonest (GROUP_SWEEP_INTERVAL_SECONDS).
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int,park?:'tooLong'|'timeout'}
	 */
	public function collectGroup(string $groupId): array {
		$result = $this->collectFor(['groupId' => $groupId], function (RunBudget $budget) use ($groupId): array {
			$sessions = $this->etherpadClient->listSessionsOfGroup($groupId, $budget->callTimeout(), $unreadable, EtherpadClient::SESSION_LISTING_MAX_BYTES);
			return [$sessions, $unreadable];
		}, EtherpadErrorClassifier::isPadAlreadyDeleted(...));
		if ($result['nextDueAt'] !== null) {
			$result['nextDueAt'] = max($result['nextDueAt'], $this->timeFactory->getTime() + self::GROUP_SWEEP_INTERVAL_SECONDS);
		}
		return $result;
	}

	/**
	 * One run: $list gives the sessions and how many entries it could not
	 * describe, $context names whose they are, and $gone tells from a
	 * listing that failed whether Etherpad no longer has them - each
	 * listing's own answer, no other. The expired ones are deleted
	 * (collectFrom()), or the failure is read (listingFailed()).
	 *
	 * @param array<string,string> $context
	 * @param \Closure(RunBudget): array{0: array<array-key,array{groupID:string,validUntil:int}>, 1: ?int} $list
	 * @param \Closure(\Throwable): bool $gone
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int,park?:'tooLong'|'timeout'}
	 */
	private function collectFor(array $context, \Closure $list, \Closure $gone): array {
		$budget = new RunBudget($this->timeFactory, $this->budgetSeconds);
		try {
			[$sessions, $unreadable] = $list($budget);
		} catch (\Throwable $e) {
			return $this->listingFailed($e, $budget, $context, $gone($e));
		}

		return $this->collectFrom($sessions, $unreadable, $budget, $context);
	}

	/**
	 * What a listing that failed means for the sweep. $gone, the author or
	 * group Etherpad no longer has: nothing to collect, and the sweep ends.
	 * A listing too long to read, or timing out while Etherpad answers
	 * otherwise, asks to park the sweep (SessionSweepJob). Anything else -
	 * Etherpad refusing, an HTTP error, not answering at all - is tried
	 * again with the backoff.
	 *
	 * @param array<string,string> $context whose sessions they are
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int,park?:'tooLong'|'timeout'}
	 */
	private function listingFailed(\Throwable $e, RunBudget $budget, array $context, bool $gone): array {
		if ($gone) {
			$this->logger->debug('Nothing to collect: Etherpad no longer has these sessions\' author or group.', [
				'app' => 'etherpad_nextcloud',
				...$context,
			]);
			return ['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null];
		}
		$park = match (true) {
			$e instanceof EtherpadTooLargeException => 'tooLong',
			$this->tooSlowToList($e, $budget) => 'timeout',
			default => null,
		};
		if ($park !== null) {
			// What becomes of the sweep is the job's to say; this line
			// keeps the error.
			$this->logger->debug('Could not read the Etherpad sessions to collect in a run.', [
				'app' => 'etherpad_nextcloud',
				...$context,
				...SafeError::context($e),
			]);
			return ['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'park' => $park];
		}
		$this->logger->warning('Could not list the Etherpad sessions to collect.', [
			'app' => 'etherpad_nextcloud',
			...$context,
			...SafeError::context($e),
		]);
		return ['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null];
	}

	/**
	 * Whether a listing that timed out was this listing's alone: Etherpad
	 * answers otherwise. An HTTP error or a broken answer is no slowness;
	 * and a timeout with no time left to ask is taken for an outage.
	 */
	private function tooSlowToList(\Throwable $e, RunBudget $budget): bool {
		if (!EtherpadClientException::isEtherpadUnreachable($e) || !EtherpadErrorClassifier::isTimeout($e)) {
			return false;
		}
		try {
			return $this->padLifecycle->answers($budget);
		} catch (RunBudgetSpentException) {
			return false;
		}
	}

	/**
	 * The expired ones among $sessions deleted, within $budget.
	 *
	 * @param array<array-key,array{groupID:string,validUntil:int}> $sessions
	 * @param array<string,string> $context whose sessions they are
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int}
	 */
	private function collectFrom(array $sessions, ?int $unreadable, RunBudget $budget, array $context): array {
		if (($unreadable ?? 0) > 0) {
			// Keys the index lists but Etherpad cannot describe, which no
			// run deletes - whether one has expired cannot be told - so that
			// part of the index does not shrink: worth saying rather than
			// reporting a clean sweep.
			$this->logger->warning('Etherpad lists sessions it cannot describe; those entries cannot be collected.', [
				'app' => 'etherpad_nextcloud',
				...$context,
				'unreadableEntries' => $unreadable,
			]);
		}

		$now = $this->timeFactory->getTime();
		$expired = [];
		$nextDueAt = null;
		foreach ($sessions as $sessionId => $info) {
			// Live sessions are left alone: ending someone's access is not a
			// housekeeping decision.
			$dueAt = SessionDeletes::expiredAt($info['validUntil']);
			if ($dueAt <= $now) {
				$expired[$sessionId] = $info;
				continue;
			}

			// When the earliest becomes collectable — without it, a sweep
			// that found nothing is queued again by the very next open.
			$nextDueAt = $nextDueAt === null ? $dueAt : min($nextDueAt, $dueAt);
		}

		$run = $this->deletes->within($budget, $expired, self::MAX_PER_RUN, $context, 'Could not collect an expired Etherpad session.');
		$deleted = $run['deleted'];
		$remaining = count($expired) - $run['handled'];

		if ($deleted > 0 || $remaining > 0) {
			$this->logger->debug('Collected expired Etherpad sessions.', [
				'app' => 'etherpad_nextcloud',
				...$context,
				'deleted' => $deleted,
				'remaining' => $remaining,
			]);
		}

		return ['deleted' => $deleted, 'remaining' => $remaining, 'retry' => $run['refused'], 'nextDueAt' => $nextDueAt];
	}
}
