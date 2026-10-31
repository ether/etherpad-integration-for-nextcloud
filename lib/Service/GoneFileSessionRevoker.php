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
 * Queues the groups of the protected pads whose files were seen deleted for
 * good (GoneFilesListener), for what no delete's request reached: a file or
 * folder purged from a trash, a folder past the trash, an account's home.
 * They go to a job in batches, to keep a delete's request from queueing
 * thousands; it queues each group's own job (GroupSessionRevoker), which
 * decides whether its sessions go.
 *
 * Whatever delete_pad_with_file says: that decides whether a pad stays, not
 * whether what gave access to it still does. A pad the sweep is to take may
 * be refused, and wait.
 *
 * The listener builds this on a process's first file cache removal, so it
 * does not depend on what talks to Etherpad.
 */
class GoneFileSessionRevoker {
	/**
	 * Groups per job. Etherpad-generated group ids keep each batch below
	 * Nextcloud's 32,000-byte limit for JSON-encoded arguments.
	 */
	private const BATCH = 100;

	/**
	 * Due-time offset per group handed over in this process, 30 seconds per
	 * batch, which runs out as time passes. Overdue jobs may run together.
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
	 * Queue the jobs for the groups of the protected ones of $fileIds. Never
	 * fails what called it: what could not be queued goes to no job, and says
	 * so, and a batch that fails leaves the next to try.
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
		foreach (array_chunk(array_values($groups), self::BATCH) as $batch) {
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
