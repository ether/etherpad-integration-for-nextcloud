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
 * by side: the link's visitors share the group the pad is in, whatever
 * author each opens as, so one job a group holds them all where a job an
 * author would be one a visitor. The author's sweep stays because a group
 * can hold more sessions than a run can list in time - every user's, and
 * a legacy group's other pads' - and the author's index, often a smaller
 * one, is collected all the same.
 */
class ExpiredSessionCollector {

	private const MAX_PER_RUN = 250;
	/** The budget is a parameter so a test can reach it, not a setting. */
	public function __construct(
		private EtherpadClient $etherpadClient,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
		private SessionDeletes $deletes,
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
	 * did not get through - a listing that failed or timed out, a delete
	 * refused. They must stay separate: the job removes its own
	 * row before running, so a swallowed failure loses the backlog, and a
	 * failure read as progress has the job returning every minute for good.
	 * `nextDueAt` is when the earliest session still standing becomes
	 * collectable, or null when the author holds nothing.
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int}
	 */
	public function collect(string $authorId): array {
		$budget = new RunBudget($this->timeFactory, $this->budgetSeconds);
		$context = ['authorId' => $authorId];

		try {
			$sessions = $this->etherpadClient->listSessionsOfAuthor(
				$authorId,
				$budget->callTimeout(),
				$unreadable,
			);
		} catch (\Throwable $e) {
			return $this->listingFailed($e, $context);
		}

		return $this->collectFrom($sessions, $unreadable, $budget, $context);
	}

	/**
	 * collect() for a group's sessions, every author's: a public link's
	 * (noteGroup()). A group Etherpad no longer has holds none, and the
	 * sweep ends.
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int}
	 */
	public function collectGroup(string $groupId): array {
		$budget = new RunBudget($this->timeFactory, $this->budgetSeconds);
		$context = ['groupId' => $groupId];

		try {
			$sessions = $this->etherpadClient->listSessionsOfGroup(
				$groupId,
				$budget->callTimeout(),
				$unreadable,
			);
		} catch (\Throwable $e) {
			if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				return ['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null];
			}
			return $this->listingFailed($e, $context);
		}

		return $this->collectFrom($sessions, $unreadable, $budget, $context);
	}

	/**
	 * @param array<string,string> $context whose sessions they are
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:?int}
	 */
	private function listingFailed(\Throwable $e, array $context): array {
		$this->logger->warning('Could not list the Etherpad sessions to collect.', [
			'app' => 'etherpad_nextcloud',
			...$context,
			...SafeError::context($e),
		]);
		return ['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null];
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
