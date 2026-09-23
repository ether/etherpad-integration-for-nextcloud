<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Settles the bindings that wait: restores Etherpad could not answer for,
 * and deletions a trash owed. What becomes of each goes by where its file
 * is now:
 *
 * - in Files: the decision a restore takes, taken again - by this sweep,
 *   which has no restore event to go by;
 * - in the trash: nothing yet, the deletion waits for the file;
 * - gone for good, with nothing left of it in the file cache: the
 *   deletion is carried out. The only place a pad is deleted from here.
 *
 * Bounded the way ExpiredSessionCollector is: a RunBudget for the time,
 * and a run that gives up after a few rows Etherpad gave no answer for. The rows stay until they are settled,
 * so without the bound an Etherpad that is down would cost every run a
 * full timeout per row.
 *
 * @psalm-api
 */
class PendingBindingService {
	private const BUDGET_SECONDS = 20.0;

	/** Rows without an answer a run puts up with before reading them as an outage. */
	private const MAX_FAILURES_PER_RUN = 5;

	private const TRASH_PREFIX = 'files_trashbin/';

	/** The budget is a parameter so a test can reach it, not a setting. */
	public function __construct(
		private BindingService $bindingService,
		private LifecycleService $lifecycleService,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
		private float $budgetSeconds = self::BUDGET_SECONDS,
	) {
	}

	/** @return array{checked:int, settled:int, pending_restores:int, pending_deletes:int} */
	public function settle(int $limit = 200): array {
		return [
			...$this->settleByAge(0, null, $limit),
			'pending_restores' => $this->bindingService->countByState(BindingService::STATE_RESTORE_PENDING),
			'pending_deletes' => $this->bindingService->countByState(BindingService::STATE_PENDING_DELETE),
		];
	}

	/**
	 * Restores first: a row that waits keeps its file from opening, a
	 * deletion owed keeps nothing from anyone.
	 *
	 * @return array{checked:int, settled:int}
	 */
	public function settleByAge(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit = 200): array {
		$budget = new RunBudget($this->timeFactory, $this->budgetSeconds);
		$checked = 0;
		$settled = 0;
		$unanswered = 0;

		$rows = $this->bindingService->findRestorePendingByAge($minAgeSeconds, $maxAgeSeconds, max(1, $limit));
		foreach ($this->bindingService->findPendingDeleteByAge($minAgeSeconds, $maxAgeSeconds, max(1, $limit)) as $row) {
			$path = $row['file_path'] ?? null;
			if (!is_string($path) || !str_starts_with($path, self::TRASH_PREFIX)) {
				$rows[] = $row;
			}
		}

		foreach ($rows as $row) {
			$fileId = (int)($row['file_id'] ?? 0);
			$padId = (string)($row['pad_id'] ?? '');
			if ($fileId <= 0 || $padId === '') {
				continue;
			}
			if (!$budget->fitsAnotherCall() || $unanswered >= self::MAX_FAILURES_PER_RUN) {
				break;
			}
			$outcome = $this->settleRow($row, $fileId, $padId, $budget->callTimeout());
			if ($outcome === null) {
				continue;
			}
			$checked++;
			if ($outcome === SettleOutcome::Settled) {
				$settled++;
			} elseif ($outcome === SettleOutcome::Unanswered) {
				$unanswered++;
			}
		}
		return ['checked' => $checked, 'settled' => $settled];
	}

	/**
	 * A deletion owed whose file has left no trace in the file cache is
	 * carried out; every other row belongs to a file in Files. Null when
	 * the row was not looked at at all.
	 *
	 * @param array<string,mixed> $row
	 */
	private function settleRow(array $row, int $fileId, string $padId, int $timeout): ?SettleOutcome {
		try {
			if ((string)($row['state'] ?? '') === BindingService::STATE_PENDING_DELETE && ($row['file_path'] ?? null) === null) {
				return $this->lifecycleService->finishOwedDeletion($fileId, $padId, $timeout);
			}
			$node = $this->rootFolder->getFirstNodeById($fileId);
			// One that cannot be found, or sits in a trash after all, is left
			// for a later run rather than guessed at.
			if (!$node instanceof File || str_contains($node->getPath(), '/files_trashbin/')) {
				return null;
			}
			return $this->lifecycleService->settleWaitingFile($node, $timeout);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not settle a pad binding that waits.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
			return SettleOutcome::Unanswered;
		}
	}
}
