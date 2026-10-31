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
 * job (GroupSessionRevoker), which decides whether its sessions go; a batch
 * of them first, to keep a delete's request from queueing thousands.
 *
 * Whatever delete_pad_with_file says: that decides whether a pad stays, not
 * whether what gave access to it still does. A pad the sweep is to take may
 * be refused, and wait.
 */
class GoneFileSessionRevoker {
	/**
	 * Groups per job. Etherpad-generated group ids keep each batch below
	 * Nextcloud's 32,000-byte limit for JSON-encoded arguments.
	 */
	private const BATCH = 100;

	/**
	 * Due-time offset per 100 distinct groups handed over in this process.
	 * Overdue jobs may run together.
	 */
	private const SECONDS_A_BATCH = 30;

	/** @var array<string,true> groups this process handed over: what paces a trash emptied one delete at a time as one large one */
	private array $handedOver = [];

	public function __construct(
		private BindingService $bindingService,
		private GroupSessionRevoker $groupSessions,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Queue the jobs for the groups of the protected ones of $fileIds. Never
	 * fails what called it: what could not be queued goes to no job, and says
	 * so.
	 *
	 * @param list<int> $fileIds
	 */
	public function queue(array $fileIds): void {
		try {
			$groups = [];
			foreach ($this->bindingService->protectedPadsOfFiles($fileIds) as $padId) {
				$groupId = PadId::groupIdOf($padId);
				if ($groupId !== null && !isset($this->handedOver[$groupId])) {
					$groups[$groupId] = $groupId;
				}
			}
			foreach (array_chunk(array_values($groups), self::BATCH) as $batch) {
				$runAfter = $this->timeFactory->getTime() + intdiv(count($this->handedOver), self::BATCH) * self::SECONDS_A_BATCH;
				$this->jobList->scheduleAfter(RevokeGoneFilesSessionsJob::class, $runAfter, ['groupIds' => $batch]);
				$this->handedOver += array_fill_keys($batch, true);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not queue the groups of files deleted for good; their sessions will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * Queues $groupIds, within a run's budget. `left` is what a later pass
	 * still has to do; `failed` that a group could not be queued, which
	 * queue() has said.
	 *
	 * @param list<string> $groupIds
	 * @return array{left:list<string>,failed:bool}
	 */
	public function pass(array $groupIds): array {
		$budget = new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS);
		$result = ['left' => [], 'failed' => false];
		foreach ($groupIds as $index => $groupId) {
			if ($budget->exhausted()) {
				$result['left'] = array_slice($groupIds, $index);
				break;
			}
			if (!$this->groupSessions->queueUnlessWaiting($groupId)) {
				$result = ['left' => array_slice($groupIds, $index), 'failed' => true];
				break;
			}
		}
		return $result;
	}
}
