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
 * Finds the protected groups under a trashed folder after the request's
 * bounded walk (ProtectedPadsOfNode). GroupSessionRevoker decides whether
 * revoking is safe.
 *
 * Scanning the rows avoids storing the remaining folder tree in a job's
 * argument. What it costs depends on the instance's active protected rows,
 * not the folder's entries (docs/architecture.md, "Cost at scale"). A
 * missing folder cannot be matched to its former descendants.
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
	 * Scans the active protected rows past $after; never throws. The time
	 * budget is checked between pages and before queueing a group; running
	 * queries are not interrupted.
	 *
	 * `after` is the last row processed; a failed queueing leaves its row for
	 * the next pass. `ended` stops this scan, not the group jobs it queued.
	 * `failed` reports a look-up or queueing failure.
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
						$queued[$groupId] = $groupId;
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
