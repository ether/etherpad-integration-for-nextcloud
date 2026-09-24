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
 * The sweep: settles the bindings that wait - restores Etherpad could not
 * answer for, and deletions a trash owed - for the background jobs and the
 * admin page. Each row goes by where its file is now (settleRow): to the
 * decision a restore takes, for a file in Files, or to the deletion its
 * trash owed (OwedDeletions).
 *
 * A run takes the kinds in turn (rowsInTurn) and each row under its
 * SettleLock (whileHeld), and is bounded by a RunBudget.
 */
class PendingBindingService {
	/** The budget is a parameter so a test can reach it, not a setting. */
	public function __construct(
		private BindingService $bindingService,
		private AppConfigService $appConfig,
		private RestoreService $restoreService,
		private OwedDeletions $owedDeletions,
		private IRootFolder $rootFolder,
		private SettleLock $settleLock,
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
			// A row the database holds with no file or no pad names nothing to settle.
			if ($row->fileId <= 0 || $row->padId === '') {
				continue;
			}
			if ($budget->exhausted()) {
				break;
			}
			$outcome = $this->whileHeld($row->fileId, fn (): ?SettleOutcome => $this->settleRow($row, $budget));
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
	 * undecided, then deletions owed by where the file is (FileLocation).
	 * Each kind is asked for the whole limit, and what one leaves goes to
	 * the others, so a run that ends on its budget has still reached every
	 * kind.
	 *
	 * While deleting on trash is switched off, deletions owed in a trash or
	 * gone for good are not fetched: they could only wait, in the places of
	 * rows in Files, which are settled either way.
	 *
	 * @return list<WaitingBinding>
	 */
	private function rowsInTurn(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit): array {
		$fileLocations = $this->appConfig->isDeleteOnTrashEnabled()
			? [FileLocation::Gone, FileLocation::InUserTrash, FileLocation::Elsewhere]
			: [FileLocation::Elsewhere];
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
	 * A lock that cannot be taken for another reason - the database, say -
	 * costs this row, not the run, as any local failure does.
	 *
	 * @param \Closure(): ?SettleOutcome $settle
	 */
	private function whileHeld(int $fileId, \Closure $settle): ?SettleOutcome {
		try {
			return $this->settleLock->holding($fileId, $settle, static fn (): ?SettleOutcome => null);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not settle a pad binding that waits.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
			return SettleOutcome::Left;
		}
	}

	/**
	 * The row settled by where its file is now (WaitingBinding::location()).
	 * Null when it was not looked at: its file cannot be reached where the
	 * file cache says it is, as in a team folder's trash
	 * (BindingService::TEAM_TRASH_PATH). A deletion owed moves to the back
	 * then, so rows like it take their turn after the others.
	 */
	private function settleRow(WaitingBinding $row, RunBudget $budget): ?SettleOutcome {
		try {
			return match ($row->location()) {
				FileLocation::Gone => $this->owedDeletions->finishGoneFile($row, $budget),
				FileLocation::InUserTrash => $this->settleInUserTrash($row, $budget),
				FileLocation::Elsewhere => $this->settleElsewhere($row, $budget),
				null => $this->notReached($row),
			};
		} catch (\Throwable $e) {
			// Etherpad's silence is caught where it is met; what arrives here
			// is local - the database, a storage - and says nothing about
			// Etherpad, so it does not count towards the outage stop.
			$this->logger->warning('Could not settle a pad binding that waits.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $row->fileId,
				...SafeError::context($e),
			]);
			return SettleOutcome::Left;
		}
	}

	/**
	 * A file anywhere else is settled as a restore would settle it, once a
	 * node outside every trash reaches it.
	 */
	private function settleElsewhere(WaitingBinding $row, RunBudget $budget): ?SettleOutcome {
		$file = $this->nodeById($row->fileId, inTrash: false);
		return $file === null ? $this->notReached($row) : $this->restoreService->settleWaitingFile($file, $budget);
	}

	/**
	 * A deletion owed whose file is in its owner's trash is finished there;
	 * a restore left undecided has nothing to be done in a trash.
	 */
	private function settleInUserTrash(WaitingBinding $row, RunBudget $budget): ?SettleOutcome {
		if ($row->state !== BindingService::STATE_PENDING_DELETE) {
			return $this->notReached($row);
		}
		$file = $this->nodeById($row->fileId, inTrash: true);
		return $file === null ? $this->notReached($row) : $this->owedDeletions->finishTrash($file, $budget);
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
	private function notReached(WaitingBinding $row): ?SettleOutcome {
		if ($row->state === BindingService::STATE_PENDING_DELETE) {
			$this->bindingService->transition($row->fileId, $row->padId, $row->state, $row->state);
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
