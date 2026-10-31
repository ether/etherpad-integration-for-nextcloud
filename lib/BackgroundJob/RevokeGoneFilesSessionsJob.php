<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\GroupSessionRevoker;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Queues each group's own job from a batch of the groups of files deleted
 * for good (GoneFileSessionRevoker), within a run's budget, under the groups
 * still to go.
 */
class RevokeGoneFilesSessionsJob extends ResumingJob {
	public function __construct(
		ITimeFactory $time,
		private GroupSessionRevoker $groupSessions,
		IJobList $jobList,
		LoggerInterface $logger,
	) {
		parent::__construct($time, $jobList, $logger);
	}

	protected function pass(array $argument): ?array {
		// Stored job arguments may be malformed; only non-empty strings remain.
		$groupIds = array_values(array_filter((array)($argument['groupIds'] ?? null), static fn (mixed $groupId): bool => is_string($groupId) && $groupId !== ''));
		if ($groupIds === []) {
			return null;
		}

		$budget = new RunBudget($this->time, RunBudget::DEFAULT_SECONDS);
		foreach ($groupIds as $index => $groupId) {
			$spent = $budget->exhausted();
			if ($spent || !$this->groupSessions->queueUnlessWaiting($groupId)) {
				$left = array_slice($groupIds, $index);
				return [
					'next' => ['groupIds' => $left],
					'progress' => $index > 0,
					'failed' => !$spent,
					'context' => ['groups' => count($left), 'groupId' => $left[0]],
				];
			}
		}
		return null;
	}

	protected function gaveUpMessage(): string {
		return 'Gave up on the groups of files deleted for good after three retries without progress; their sessions expire on their own.';
	}

	protected function lostRunMessage(): string {
		return 'Could not queue the next run for the groups of files deleted for good; their sessions expire on their own.';
	}
}
