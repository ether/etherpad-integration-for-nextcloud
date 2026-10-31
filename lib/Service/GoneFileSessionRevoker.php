<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGoneFilesSessionsJob;
use OCA\EtherpadNextcloud\Util\PadId;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Queues protected groups after GoneFilesListener observes permanent file
 * removal. Batch jobs queue the group jobs that decide whether session
 * revocation is safe.
 *
 * Session revocation is independent of delete_pad_with_file: sessions may
 * need revocation even when pad deletion is disabled or fails.
 *
 * This service has no Etherpad client dependency because the listener is
 * constructed for file events.
 */
class GoneFileSessionRevoker {
	/**
	 * Groups per job. Etherpad-generated group ids keep each batch below
	 * Nextcloud's 32,000-byte limit for JSON-encoded arguments.
	 */
	private const BATCH = 100;

	/**
	 * Adds 300 ms per group to the next batch's earliest due time. Elapsed
	 * time consumes the accumulated delay; overdue jobs may run together.
	 */
	private const MILLISECONDS_A_GROUP = 300;

	/** The earliest the next batch of this process is due, in milliseconds: what paces a trash emptied one delete at a time as one large one. */
	private int $nextDue = 0;

	public function __construct(
		private BindingService $bindingService,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Queues protected groups for $fileIds in batches. Lookup and enqueue
	 * failures are logged; a failed batch does not stop later batches.
	 *
	 * @param list<int> $fileIds
	 */
	public function queue(array $fileIds): void {
		try {
			$groups = [];
			foreach ($this->bindingService->protectedPadsOfFiles($fileIds) as $padId) {
				$groupId = PadId::groupIdOf($padId);
				if ($groupId !== null) {
					$groups[$groupId] = $groupId;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not read the groups of files deleted for good; their sessions will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
			return;
		}
		foreach (array_chunk($groups, self::BATCH) as $batch) {
			$runAfter = max($this->timeFactory->getTime() * 1000, $this->nextDue);
			try {
				$this->jobList->scheduleAfter(RevokeGoneFilesSessionsJob::class, intdiv($runAfter, 1000), ['groupIds' => $batch]);
				$this->nextDue = $runAfter + count($batch) * self::MILLISECONDS_A_GROUP;
			} catch (\Throwable $e) {
				$this->logger->warning('Could not queue the groups of files deleted for good; their sessions will expire on their own.', [
					'app' => 'etherpad_nextcloud',
					'groups' => count($batch),
					'groupId' => $batch[0],
					...SafeError::context($e),
				]);
			}
		}
	}
}
