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
 * Collects the Etherpad sessions that have already expired.
 *
 * Etherpad never removes one, and `listSessionsOfAuthor` walks the whole
 * author index one awaited lookup at a time, so a listing - an open makes
 * one when the browser carries session ids, a logout always - costs as
 * many lookups as there were opens. Collecting them happens in no
 * request: an open leaves the author's id, and the job lists the expired
 * ones and deletes them.
 *
 * A public link's open notes its group as well, and both sweeps run side
 * by side. The group's takes every author's expired sessions in it - a
 * signed-in user's too, while they open no protected pad that would
 * queue their own - which keeps short the listing a delete's revoke
 * reads; and it is one job a group whatever authors open through the
 * link: today a link's visitors all open as its author, and should each
 * open as an author of their own, a job an author would be one a
 * visitor. The author's sweep stays because a group can hold more
 * sessions than a run can list in time - every user's, and a legacy
 * group's other pads' - and the author's index, often a smaller one, is
 * collected all the same.
 */
class ExpiredSessionCollector {

	private const MAX_PER_RUN = 250;

	/**
	 * The least a group's sweep waits before it comes back for a session
	 * still live. A group's live sessions are everyone's - signed-in users
	 * at the pad, for six hours each - so the earliest to expire is never
	 * far off while anyone uses it, and each pass lists the whole group:
	 * expired sessions may wait an hour longer, the listing may not run
	 * at every cron tick. A backlog is worked off as an author's is, a
	 * pass a minute, each listing the group anew as it shrinks.
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

	/**
	 * Remember that the group a public link opened might have something to
	 * collect, as noteAuthor() does for an author: the link's uid holds its
	 * share token, which no job argument may.
	 */
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
	 * run - too long, or timing out while Etherpad answers otherwise
	 * (SessionSweepJob parks the sweep, after a timeout on a retry). An
	 * author Etherpad does not know holds nothing, and the sweep ends.
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int,park?:'tooLong'|'timeout'}
	 */
	public function collect(string $authorId): array {
		$budget = new RunBudget($this->timeFactory, $this->budgetSeconds);
		$context = ['authorId' => $authorId];

		try {
			$sessions = $this->etherpadClient->listSessionsOfAuthor(
				$authorId,
				$budget->callTimeout(),
				$unreadable,
				EtherpadClient::SESSION_LISTING_MAX_BYTES,
			);
		} catch (\Throwable $e) {
			return $this->listingFailed($e, $budget, $context, EtherpadErrorClassifier::isAuthorUnknown($e));
		}

		return $this->collectFrom($sessions, $unreadable, $budget, $context);
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
		$budget = new RunBudget($this->timeFactory, $this->budgetSeconds);
		$context = ['groupId' => $groupId];

		try {
			$sessions = $this->etherpadClient->listSessionsOfGroup(
				$groupId,
				$budget->callTimeout(),
				$unreadable,
				EtherpadClient::SESSION_LISTING_MAX_BYTES,
			);
		} catch (\Throwable $e) {
			return $this->listingFailed($e, $budget, $context, EtherpadErrorClassifier::isPadAlreadyDeleted($e));
		}

		$result = $this->collectFrom($sessions, $unreadable, $budget, $context);
		if ($result['nextDueAt'] !== null) {
			$result['nextDueAt'] = max($result['nextDueAt'], $this->timeFactory->getTime() + self::GROUP_SWEEP_INTERVAL_SECONDS);
		}
		return $result;
	}

	/**
	 * What a listing that failed means for the sweep. $gone, the author or
	 * group Etherpad no longer has: nothing to collect, and the sweep ends.
	 * A listing too long to read, or timing out while Etherpad answers
	 * otherwise: no run reads it sooner, and the sweep asks to be parked
	 * (SessionSweepJob, which tries a first timeout again before it
	 * parks). Anything else - Etherpad refusing, an HTTP error, not
	 * answering at all - is tried again with the backoff.
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
			// The job says the sweep is parked, and why; this line keeps
			// the error itself.
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

		$cutoff = $this->timeFactory->getTime() - EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS;
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
			$dueAt = $info['validUntil'] + EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS;
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
