<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Settles the bindings that wait: restores Etherpad could not answer for,
 * and deletions a trash owed. What becomes of each goes by where its file
 * is now:
 *
 * - in Files: the decision a restore takes, taken again, except that a
 *   sweep writes no file there (LifecycleService::settleWaitingFile);
 * - in its owner's trash: the snapshot the trash could not take, written
 *   into the trashed file, then row and pad deleted, in that order
 *   (LifecycleService::finishTrash). The one file a sweep writes, and
 *   always on its owner's own storage;
 * - in a team folder's trash: nothing, since no node reaches it; the row
 *   waits until that trash lets the file go;
 * - gone for good, with nothing left of it in the file cache: the pad is
 *   deleted, then the row (LifecycleService::finishGoneFile).
 *
 * Both deletions wait while deleting on trash is switched off, and their
 * rows are not even fetched then: they would only take the places of
 * rows in Files, which are settled either way.
 *
 * Bounded by a RunBudget: each Etherpad call gets what is left of the run,
 * none is started that could not finish - except the deletion of a pad
 * whose row is already taken, which finishes on the client's own
 * timeouts - and a run ends after a few rows Etherpad gave no answer for.
 * The rows stay until they are settled, so without that an Etherpad that
 * is down would cost every run a full timeout per row.
 *
 * A row is settled by one run at a time: the jobs and the admin page can
 * run at once, and each holds a lock on the row while it works on it.
 *
 * @psalm-api
 */
class PendingBindingService {
	/** The budget is a parameter so a test can reach it, not a setting. */
	public function __construct(
		private BindingService $bindingService,
		private LifecycleService $lifecycleService,
		private IRootFolder $rootFolder,
		private ILockingProvider $locks,
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
	 * $limit counts rows of every kind together.
	 *
	 * @return array{checked:int, settled:int}
	 */
	public function settleByAge(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit = 200): array {
		$budget = new RunBudget($this->timeFactory, $this->budgetSeconds);
		$checked = 0;
		$settled = 0;
		foreach ($this->rowsInTurn($minAgeSeconds, $maxAgeSeconds, $limit) as $row) {
			$fileId = (int)($row['file_id'] ?? 0);
			$padId = (string)($row['pad_id'] ?? '');
			if ($fileId <= 0 || $padId === '') {
				continue;
			}
			if ($budget->exhausted()) {
				break;
			}
			$path = $row['file_path'] ?? null;
			$outcome = $this->whileHeld($fileId, fn (): ?SettleOutcome => $this->settleRow($fileId, $padId, (string)($row['state'] ?? ''), is_string($path) ? $path : null, $budget));
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

	/**
	 * The rows a run takes, one of each kind in turn: restores left
	 * undecided, then deletions owed by where the file is - gone for good,
	 * in a user's trash, anywhere else. Each kind is asked for the whole
	 * limit, and what one leaves goes to the others. Rows no run can settle
	 * yet then crowd out only rows of their own kind, which move to the
	 * back, and a run that ends on its budget has still reached every kind.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rowsInTurn(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit): array {
		$fileLocations = $this->lifecycleService->isDeleteOnTrashEnabled()
			? [BindingService::FILE_GONE, BindingService::FILE_IN_USER_TRASH, BindingService::FILE_ELSEWHERE]
			: [BindingService::FILE_ELSEWHERE];
		$kinds = [$this->bindingService->findRestorePendingByAge($minAgeSeconds, $maxAgeSeconds, $limit)];
		foreach ($fileLocations as $fileLocation) {
			$kinds[] = $this->bindingService->findPendingDeleteByAge($minAgeSeconds, $maxAgeSeconds, $limit, $fileLocation);
		}

		$rows = [];
		for ($turn = 0; count($rows) < $limit; $turn++) {
			$taken = 0;
			foreach ($kinds as $kind) {
				if (isset($kind[$turn]) && count($rows) < $limit) {
					$rows[] = $kind[$turn];
					$taken++;
				}
			}
			if ($taken === 0) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * $settle with this row to itself. Two runs at once - a job and the
	 * admin page - would otherwise both finish the same trash, and the one
	 * holding the older snapshot could write it last. Null when another run
	 * holds the row: it is neither counted nor moved.
	 *
	 * @param callable(): ?SettleOutcome $settle
	 */
	private function whileHeld(int $fileId, callable $settle): ?SettleOutcome {
		$lock = Application::APP_ID . ':settle:' . $fileId;
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return null;
		}
		try {
			return $settle();
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Null when the row was not looked at: its file cannot be reached where
	 * the file cache says it is. A deletion owed moves to the back then, so
	 * rows like it - a team folder's trash above all - take their turn
	 * after the others rather than before them.
	 */
	private function settleRow(int $fileId, string $padId, string $state, ?string $cachePath, RunBudget $budget): ?SettleOutcome {
		try {
			if ($cachePath === null) {
				return $this->lifecycleService->finishGoneFile($fileId, $padId, $state, $budget);
			}
			$inUserTrash = str_starts_with($cachePath, BindingService::USER_TRASH_PATH);
			$file = match (true) {
				str_starts_with($cachePath, BindingService::TEAM_TRASH_PATH) => null,
				$inUserTrash => $state === BindingService::STATE_PENDING_DELETE ? $this->nodeById($fileId, inTrash: true) : null,
				default => $this->nodeById($fileId, inTrash: false),
			};
			if ($file === null) {
				return $this->notReached($fileId, $padId, $state);
			}
			return $inUserTrash
				? $this->lifecycleService->finishTrash($file, $budget)
				: $this->lifecycleService->settleWaitingFile($file, $budget->callTimeout());
		} catch (\Throwable $e) {
			// Etherpad's silence is caught where it is met; what arrives here
			// is local - the database, a storage - and says nothing about
			// Etherpad, so it does not count towards the outage stop.
			$this->logger->warning('Could not settle a pad binding that waits.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
			return SettleOutcome::Left;
		}
	}

	/**
	 * The file by its id, found without a session, in a trash or outside
	 * one. In its owner's trash it is the node a trash's snapshot is
	 * written into, always on the owner's own storage, never through a
	 * share. Outside, a sweep only reads it, so any mount will do: a row
	 * settled there belongs to a file in Files.
	 */
	private function nodeById(int $fileId, bool $inTrash): ?File {
		foreach ($this->rootFolder->getById($fileId) as $node) {
			if ($node instanceof File && self::isTrashNodePath($node->getPath()) === $inTrash) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Null, and a deletion owed to the back of the queue. A restore left
	 * undecided is aged by updated_at itself, so moving it would only make
	 * it younger.
	 */
	private function notReached(int $fileId, string $padId, string $state): ?SettleOutcome {
		if ($state === BindingService::STATE_PENDING_DELETE) {
			$this->bindingService->transition($fileId, $padId, $state, $state);
		}
		return null;
	}

	/**
	 * A node path, absolute: `/<user>/files_trashbin/...`, or a team
	 * folder's trash. The segment is compared, not searched for, so a
	 * folder someone named files_trashbin inside their files is not one.
	 */
	private static function isTrashNodePath(string $path): bool {
		$segments = explode('/', ltrim($path, '/'), 3);
		return ($segments[1] ?? '') === rtrim(BindingService::USER_TRASH_PATH, '/')
			|| str_starts_with($path, '/' . BindingService::TEAM_TRASH_PATH);
	}
}
