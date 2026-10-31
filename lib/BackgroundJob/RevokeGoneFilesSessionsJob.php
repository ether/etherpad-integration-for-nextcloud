<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\GoneFileSessionRevoker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Hands over a batch of the groups of files deleted for good
 * (GoneFileSessionRevoker), under the groups still to go.
 */
class RevokeGoneFilesSessionsJob extends ResumingJob {
	public function __construct(
		ITimeFactory $time,
		private GoneFileSessionRevoker $revoker,
		IJobList $jobList,
		LoggerInterface $logger,
	) {
		parent::__construct($time, $jobList, $logger);
	}

	protected function pass(array $argument): ?array {
		// Stored job arguments may be malformed; what is no group's id is left out.
		$groupIds = array_values(array_filter((array)($argument['groupIds'] ?? null), static fn (mixed $groupId): bool => is_string($groupId) && $groupId !== ''));
		if ($groupIds === []) {
			return null;
		}

		$pass = $this->revoker->pass($groupIds);
		if ($pass['left'] === []) {
			return null;
		}
		return [
			'next' => ['groupIds' => $pass['left']],
			'progress' => count($pass['left']) < count($groupIds),
			'failed' => $pass['failed'],
			'context' => ['groups' => count($pass['left']), 'groupId' => $pass['left'][0]],
		];
	}

	protected function gaveUpMessage(): string {
		return 'Gave up on the groups of files deleted for good after three retries without progress; their sessions expire on their own.';
	}

	protected function lostRunMessage(): string {
		return 'Could not queue the next run for the groups of files deleted for good; their sessions expire on their own.';
	}
}
