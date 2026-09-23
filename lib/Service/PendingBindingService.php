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
 * Bounded the way ExpiredSessionCollector is: a time budget, the probe's
 * timeout cut to what is left of it, and a run that gives up after a few
 * rows Etherpad gave no answer for. The rows stay until they are settled,
 * so without the bound an Etherpad that is down would cost every run a
 * full timeout per row.
 *
 * @psalm-api
 */
class PendingBindingService {
	private const BUDGET_SECONDS = 20.0;

	/** Rows without an answer a run puts up with before reading them as an outage. */
	private const MAX_FAILURES_PER_RUN = 5;

	/** Below this, a probe cannot finish inside the budget and is not made. */
	private const MIN_CALL_TIMEOUT_SECONDS = 2;

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
		$deadline = $this->nowSeconds() + $this->budgetSeconds;
		$tally = ['checked' => 0, 'settled' => 0, 'failed' => 0];

		foreach ($this->bindingService->findRestorePendingByAge($minAgeSeconds, $maxAgeSeconds, max(1, $limit)) as $row) {
			if (!$this->settleRow($row, null, $deadline, $tally)) {
				return $this->report($tally);
			}
		}
		foreach ($this->bindingService->findPendingDeleteByAge($minAgeSeconds, $maxAgeSeconds, max(1, $limit)) as $row) {
			$path = $row['file_path'] ?? null;
			if (is_string($path) && str_starts_with($path, self::TRASH_PREFIX)) {
				continue;
			}
			if (!$this->settleRow($row, is_string($path) ? $path : null, $deadline, $tally)) {
				return $this->report($tally);
			}
		}
		return $this->report($tally);
	}

	/**
	 * One row, if the run can still afford it. False ends the run: out of
	 * time, or too many rows without an answer.
	 *
	 * @param array<string,mixed> $row
	 * @param array{checked:int, settled:int, failed:int} $tally
	 */
	private function settleRow(array $row, ?string $cachedPath, float $deadline, array &$tally): bool {
		$fileId = (int)($row['file_id'] ?? 0);
		$padId = (string)($row['pad_id'] ?? '');
		if ($fileId <= 0 || $padId === '') {
			return true;
		}
		$left = $deadline - $this->nowSeconds();
		if ($left < self::MIN_CALL_TIMEOUT_SECONDS || $tally['failed'] >= self::MAX_FAILURES_PER_RUN) {
			return false;
		}
		$timeout = (int)min(floor($left), EtherpadClient::REQUEST_TIMEOUT_SECONDS);

		try {
			if ((string)($row['state'] ?? '') === BindingService::STATE_PENDING_DELETE && $cachedPath === null) {
				$outcome = match ($this->lifecycleService->finishOwedDeletion($fileId, $padId, $timeout)) {
					'deleted' => 'settled',
					'kept' => 'skipped',
					default => 'unknown',
				};
			} else {
				$outcome = $this->settleFileInFiles($fileId, $timeout);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not settle a pad binding that waits.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
			$outcome = 'unknown';
		}

		if ($outcome !== 'skipped') {
			$tally['checked']++;
		}
		if ($outcome === 'settled') {
			$tally['settled']++;
		} elseif ($outcome === 'unknown') {
			$tally['failed']++;
		}
		return true;
	}

	/**
	 * A file in Files whose row still waits. Found by its id across every
	 * user's files; one that cannot be found, or sits in a trash after all,
	 * is left for a later run rather than guessed at.
	 *
	 * @return 'settled'|'unknown'|'skipped'
	 */
	private function settleFileInFiles(int $fileId, int $timeout): string {
		$node = $this->rootFolder->getFirstNodeById($fileId);
		if (!$node instanceof File || str_contains($node->getPath(), '/files_trashbin/')) {
			return 'skipped';
		}
		$result = $this->lifecycleService->settleWaitingFile($node, $timeout);
		if (($result['status'] ?? '') === LifecycleService::RESULT_RESTORED) {
			return 'settled';
		}
		return in_array($result['reason'] ?? '', ['pad_presence_unknown', 'file_unreadable'], true) ? 'unknown' : 'skipped';
	}

	/**
	 * @param array{checked:int, settled:int, failed:int} $tally
	 * @return array{checked:int, settled:int}
	 */
	private function report(array $tally): array {
		return ['checked' => $tally['checked'], 'settled' => $tally['settled']];
	}

	/** The budget's clock, sub-second, through the same factory as the rest. */
	private function nowSeconds(): float {
		return (float)$this->timeFactory->now()->format('U.u');
	}
}
