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
 * The groups of the protected pads whose files were seen deleted for good
 * (GoneFilesListener), for what no delete's request reached: a folder past
 * the trash, or one emptied from it before its job ran. Each goes to its own
 * job (GroupSessionRevoker), which decides whether its sessions go.
 *
 * Whatever delete_pad_with_file says: that decides whether a pad stays, not
 * whether what gave access to it still does. A pad the sweep is to take may
 * be refused, and wait.
 */
class GoneFileSessionRevoker {
	/** Files a job names at a time: 64-bit ids of them stay far below what Nextcloud takes as a job's argument. */
	private const BATCH = 200;

	/** Seconds between two batches of a process: the earliest each runs, as cron may run late and catch up. */
	private const SECONDS_A_BATCH = 60;

	/** Files this process handed to jobs: what paces a trash emptied one delete at a time as one large one. */
	private int $handedOver = 0;

	public function __construct(
		private BindingService $bindingService,
		private GroupSessionRevoker $groupSessions,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Queue the jobs for the protected ones of $fileIds. Never fails what
	 * called it: what could not be queued goes to no job, and says so.
	 *
	 * @param list<int> $fileIds
	 */
	public function queue(array $fileIds): void {
		try {
			foreach (array_chunk(array_keys($this->bindingService->protectedPadsOfFiles($fileIds)), self::BATCH) as $batch) {
				$runAfter = $this->timeFactory->getTime() + intdiv($this->handedOver, self::BATCH) * self::SECONDS_A_BATCH;
				$this->jobList->scheduleAfter(RevokeGoneFilesSessionsJob::class, $runAfter, ['fileIds' => $batch]);
				$this->handedOver += count($batch);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not queue the search for the protected pads of files deleted for good; their sessions will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * Hands over the groups of $fileIds, within a run's budget; never throws.
	 * `left` is what a later pass still has to do. `failed` reports a look-up
	 * or queueing failure.
	 *
	 * @param list<int> $fileIds
	 * @return array{left:list<int>,failed:bool}
	 */
	public function pass(array $fileIds): array {
		$context = ['app' => 'etherpad_nextcloud'];
		$budget = new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS);
		$queued = [];
		$left = $fileIds;
		$failed = false;
		try {
			$pads = $this->bindingService->protectedPadsOfFiles($fileIds);
			foreach ($fileIds as $fileId) {
				$groupId = PadId::groupIdOf($pads[$fileId] ?? '');
				if ($groupId !== null && !isset($queued[$groupId])) {
					if ($budget->exhausted()) {
						break;
					}
					if (!$this->groupSessions->queue($groupId)) {
						$failed = true;
						break;
					}
					$queued[$groupId] = $groupId;
				}
				array_shift($left);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not look up the protected pads of files deleted for good.', [...$context, ...SafeError::context($e)]);
			$failed = true;
		}
		if ($queued !== []) {
			$this->logger->info('Queued the revocation of the sessions of protected pads whose files were deleted for good.', [...$context, 'groups' => count($queued)]);
		}
		return ['left' => $left, 'failed' => $failed];
	}
}
