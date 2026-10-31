<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeFolderSessionsJob;
use OCA\EtherpadNextcloud\Util\PadId;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * The groups of the protected pads under a folder in a trash, after a
 * delete whose walk stopped short (ProtectedPadsOfNode). Each goes to its
 * own job (GroupSessionRevoker), which decides whether its sessions go.
 *
 * By the rows, not by walking down again: what a walk has yet to visit does
 * not fit in a job's argument, and a file id does. What it costs grows with
 * the instance's protected pads, not the folder's entries (measured in
 * docs/architecture.md, "Cost at scale"). A folder the file cache no longer
 * has leaves nothing to tell its files by.
 */
class FolderSessionRevoker {
	/** Rows a query takes at a time. */
	private const PAGE = 500;

	public function __construct(
		private BindingService $bindingService,
		private GroupSessionRevoker $groupSessions,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
	) {
	}

	/** Queue the job for the folder. Never fails what called it: one that could not be queued says so. */
	public function queue(int $folderId): void {
		try {
			$this->jobList->add(RevokeFolderSessionsJob::class, ['folderId' => $folderId]);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not queue the search for the rest of a deleted folder\'s protected pads; their sessions will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $folderId,
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * One run over the rows past $after, within a run's budget; never
	 * throws. `after` is the last row dealt with, so what a run did not get
	 * to, a group that could not be queued among it, is met again by the
	 * next. `ended`: nothing is left to do. `failed`: a look-up failed, or a
	 * group could not be queued, which each says.
	 *
	 * @return array{after:int,ended:bool,failed:bool}
	 */
	public function pass(int $folderId, int $after): array {
		$context = ['app' => 'etherpad_nextcloud', 'fileId' => $folderId];
		$budget = new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS);
		$queued = [];
		$result = ['after' => $after, 'ended' => false, 'failed' => false];
		try {
			$folder = $this->bindingService->placeOf($folderId);
			if ($folder === null) {
				$this->logger->info('A folder deleted has left the file cache; the sessions of the protected pads a delete did not reach expire on their own.', $context);
				return ['after' => $after, 'ended' => true, 'failed' => false];
			}
			if ($this->bindingService->anyInFiles([$folderId => $folder])) {
				$this->logger->info('A folder deleted is back in Files; its protected pads keep their sessions.', $context);
				return ['after' => $after, 'ended' => true, 'failed' => false];
			}
			while (!$budget->exhausted()) {
				$pads = $this->bindingService->activeProtectedPadsAfter($result['after'], self::PAGE);
				if ($pads === []) {
					$result['ended'] = true;
					break;
				}
				$places = $this->bindingService->placesOf(array_keys($pads));
				foreach ($pads as $fileId => $padId) {
					$groupId = PadId::groupIdOf($padId);
					if ($groupId !== null && !isset($queued[$groupId]) && self::isUnder($places[$fileId] ?? null, $folder)) {
						if ($budget->exhausted()) {
							break 2;
						}
						if (!$this->groupSessions->queue($groupId)) {
							$result['failed'] = true;
							break 2;
						}
						$queued[$groupId] = true;
					}
					$result['after'] = $fileId;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not look through the rows for a deleted folder\'s protected pads.', [...$context, ...SafeError::context($e)]);
			$result['failed'] = true;
		}
		if ($queued !== []) {
			$this->logger->info('Queued the revocation of the sessions of protected pads a delete of their folder did not reach.', [...$context, 'groups' => count($queued)]);
		}
		return $result;
	}

	/**
	 * @param ?array{int,string} $place
	 * @param array{int,string} $folder
	 */
	private static function isUnder(?array $place, array $folder): bool {
		return $place !== null && $place[0] === $folder[0] && str_starts_with($place[1], $folder[1] . '/');
	}
}
