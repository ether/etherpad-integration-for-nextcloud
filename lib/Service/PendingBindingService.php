<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Settles the bindings that wait: restores Etherpad could not answer for,
 * and deletions a trash owed. What becomes of each goes by where its file
 * is now:
 *
 * - in Files: the decision a restore takes, taken again, except that a
 *   sweep writes no file (LifecycleService::settleWaitingFile);
 * - in a trash: nothing yet, the row waits for the file;
 * - gone for good, with nothing left of it in the file cache: the pad is
 *   deleted, then the row. The only place a pad is deleted from here.
 *
 * Bounded by a RunBudget: each Etherpad call gets what is left of the run,
 * none is started that could not finish, and a run ends after a few rows
 * Etherpad gave no answer for. The rows stay until they are settled, so
 * without that an Etherpad that is down would cost every run a full
 * timeout per row.
 *
 * @psalm-api
 */
class PendingBindingService {
	/** The budget is a parameter so a test can reach it, not a setting. */
	public function __construct(
		private BindingService $bindingService,
		private LifecycleService $lifecycleService,
		private ManagedPadLifecycle $padLifecycle,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
		private float $budgetSeconds = RunBudget::DEFAULT_SECONDS,
	) {
	}

	/**
	 * What a run did and what is left, under the names the health check
	 * reports the same figures by.
	 *
	 * @return array{checked:int, settled:int, pending_delete_count:int, restore_pending_count:int}
	 */
	public function settle(int $limit = 200): array {
		return [...$this->settleByAge(0, null, $limit), ...$this->bindingService->countWaiting()];
	}

	/**
	 * Restores first: a row that waits keeps its file from opening, a
	 * deletion owed keeps nothing from anyone.
	 *
	 * @return array{checked:int, settled:int}
	 */
	public function settleByAge(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit = 200): array {
		$budget = new RunBudget($this->timeFactory, $this->budgetSeconds);
		$rows = [
			...$this->bindingService->findRestorePendingByAge($minAgeSeconds, $maxAgeSeconds, $limit),
			...$this->bindingService->findPendingDeleteByAge($minAgeSeconds, $maxAgeSeconds, $limit),
		];

		$checked = 0;
		$settled = 0;
		foreach ($rows as $row) {
			$fileId = (int)($row['file_id'] ?? 0);
			$padId = (string)($row['pad_id'] ?? '');
			$path = $row['file_path'] ?? null;
			if ($fileId <= 0 || $padId === '' || (is_string($path) && self::isTrashCachePath($path))) {
				continue;
			}
			if ($budget->exhausted()) {
				break;
			}
			$outcome = $this->settleRow($fileId, $padId, (string)($row['state'] ?? ''), is_string($path), $budget);
			if ($outcome === null) {
				continue;
			}
			$checked++;
			if ($outcome === SettleOutcome::Settled) {
				$settled++;
			} elseif ($outcome === SettleOutcome::Unanswered) {
				$budget->noteFailure();
			}
		}
		return ['checked' => $checked, 'settled' => $settled];
	}

	/** Null when the row was not looked at: its file cannot be found outside a trash. */
	private function settleRow(int $fileId, string $padId, string $state, bool $fileInCache, RunBudget $budget): ?SettleOutcome {
		try {
			if (!$fileInCache) {
				return $this->finishOwedDeletion($fileId, $padId, $state, $budget);
			}
			$file = $this->fileOutsideTrash($fileId);
			return $file === null ? null : $this->lifecycleService->settleWaitingFile($file, $budget->callTimeout());
		} catch (\Throwable $e) {
			$this->logger->warning('Could not settle a pad binding that waits.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
			return SettleOutcome::Unanswered;
		}
	}

	/**
	 * The file by its id, across every user's files. Any node will do - a
	 * sweep only reads the file - except one in a trash, which is where
	 * the file is not supposed to be for a row that is settled here.
	 */
	private function fileOutsideTrash(int $fileId): ?File {
		foreach ($this->rootFolder->getById($fileId) as $node) {
			if ($node instanceof File && !self::isTrashNodePath($node->getPath())) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * A deletion owed for a file that is gone for good: nothing is left in
	 * the file cache under its id, in Files or in any trash. The pad goes,
	 * then the row, and a pad Etherpad no longer has only takes the row.
	 * Asked first, so an Etherpad that does not answer costs one call.
	 *
	 * Nothing happens while deleting on trash is switched off: this is the
	 * deletion that setting governs, only later.
	 */
	private function finishOwedDeletion(int $fileId, string $padId, string $state, RunBudget $budget): SettleOutcome {
		if ((string)$this->config->getAppValue('etherpad_nextcloud', 'delete_on_trash', 'yes') !== 'yes') {
			return SettleOutcome::Left;
		}
		$presence = $this->padLifecycle->presenceOf($padId, -1, ['fileId' => $fileId], $budget->callTimeout());
		if ($presence === PadPresence::Unknown) {
			return SettleOutcome::Unanswered;
		}
		try {
			if ($presence !== PadPresence::Absent) {
				$this->padLifecycle->discard($padId, $budget);
			}
		} catch (\Throwable $e) {
			if (!EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				$this->logger->warning('Could not delete the pad of a file that is gone for good.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					...SafeError::context($e),
				]);
				return SettleOutcome::Unanswered;
			}
		}
		// Unconditional on the answer: a file with no file cache row does not
		// come back, so no other flow is racing for this one.
		$this->bindingService->deleteInState($fileId, $padId, $state);
		return SettleOutcome::Settled;
	}

	/**
	 * A file cache path, relative to its storage: a user's trash is
	 * `files_trashbin/`, a team folder's `__groupfolders/trash/` where team
	 * folders share the root storage.
	 *
	 * A team folder with a storage of its own - groupfolders 22 on Nextcloud
	 * 34, measured - keeps its trash under a bare `trash/`, which the path
	 * alone cannot tell from a folder of that name at the root of an
	 * external storage; taken for a trash, such a file would never be
	 * settled. Its trashed files are found by no node, not even in their
	 * owner's session, so the lookup leaves them, and the file id they keep
	 * holds the deletion off until the team folder's trash removes them.
	 */
	private static function isTrashCachePath(string $path): bool {
		return str_starts_with($path, 'files_trashbin/') || str_starts_with($path, '__groupfolders/trash/');
	}

	/**
	 * A node path, absolute: `/<user>/files_trashbin/...`, or a team
	 * folder's trash. The segment is compared, not searched for, so a
	 * folder someone named files_trashbin inside their files is not one.
	 */
	private static function isTrashNodePath(string $path): bool {
		$segments = explode('/', ltrim($path, '/'), 3);
		return ($segments[1] ?? '') === 'files_trashbin' || str_starts_with($path, '/__groupfolders/trash/');
	}
}
