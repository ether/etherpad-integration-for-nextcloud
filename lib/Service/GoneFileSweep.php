<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Deletes the pads of files gone for good, and only those. The file cache
 * says what is gone; what the app saw leave Files says it is gone for good
 * (docs/architecture.md, "Files gone for good"). Active rows only: a row
 * that waits is the older sweep's (PendingBindingService).
 *
 * Two passes a run:
 * - Rows marked as leaving Files (Binding::$trashedAt) whose file the file
 *   cache has nothing left of: gone for good, so pad and row go.
 * - A slice of the active rows whose file the file cache has, with a
 *   cursor that starts over at the end. It marks a file it finds under a
 *   trash path, which a listener missed, and clears the mark of one back
 *   in Files, so a file that later goes some other way is not taken for
 *   one gone through a trash.
 *
 * A file gone without a mark is left alone, pad and row with it.
 *
 * Files deleted past the trash do not wait for a run: the request that
 * deleted them deletes their pads (discardDeleted()), and a run takes what
 * it left.
 *
 * Before a pad goes the file cache is asked once more. A pad Etherpad no
 * longer has counts as gone; Etherpad not answering ends the run. With
 * `delete_on_trash` off nothing is deleted, but the marks are kept, so
 * switching it on finds them in place.
 */
class GoneFileSweep {
	private const LIMIT = 200;

	/** What deleting the pads of files deleted past the trash may add to the request that deleted them. */
	private const REQUEST_SECONDS = 5.0;

	public function __construct(
		private BindingService $bindingService,
		private ManagedPadLifecycle $padLifecycle,
		private AppConfigService $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	public function run(): void {
		$budget = new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS);
		try {
			if ($this->appConfig->isDeleteOnTrashEnabled()) {
				foreach ($this->bindingService->findMarkedGone(self::LIMIT) as $binding) {
					$this->discard($binding, $budget);
				}
			}
		} catch (RunBudgetSpentException) {
			// The rest waits for the next run.
		} catch (EtherpadClientException $e) {
			$this->logger->info('Etherpad did not answer the sweep of files gone for good; it tries again next run.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
		$this->passSlice();
	}

	/**
	 * The pads of the files among $fileIds that a delete has just taken past
	 * the trash, in the request that deleted them (LeavingPadsListener). A
	 * file the trash took is still in the file cache and is passed by. What
	 * does not fit in a few seconds, or finds Etherpad not answering, a run
	 * takes: the rows keep their mark. Never throws: the files are gone
	 * already, and the delete has succeeded.
	 *
	 * @param list<int> $fileIds
	 */
	public function discardDeleted(array $fileIds): void {
		try {
			if ($fileIds === [] || !$this->appConfig->isDeleteOnTrashEnabled()) {
				return;
			}
			$budget = new RunBudget($this->timeFactory, self::REQUEST_SECONDS);
			foreach ($this->bindingService->findActiveGone($fileIds) as $binding) {
				$this->discard($binding, $budget);
			}
		} catch (RunBudgetSpentException) {
			// The rest is the next run's.
		} catch (\Throwable $e) {
			$this->logger->info('Could not delete the pads of files deleted past the trash; the sweep tries again.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * One slice of the pass over the active rows whose file the file cache
	 * has, from where the last one stopped. A short slice was the end of
	 * the table: the next starts over.
	 */
	private function passSlice(): void {
		$rows = $this->bindingService->findActiveWithFileAfter($this->appConfig->getGoneFileSweepCursor(), self::LIMIT);
		$last = 0;
		foreach ($rows as $row) {
			$last = $row->id;
			if ($row->isInTrash() && $row->binding->trashedAt === null) {
				$this->bindingService->markTrashed($row->binding->fileId);
			} elseif (!$row->isInTrash() && $row->binding->trashedAt !== null) {
				$this->bindingService->clearTrashed($row->binding->fileId);
			}
		}
		$this->appConfig->setGoneFileSweepCursor(count($rows) < self::LIMIT ? 0 : $last);
	}

	/**
	 * The pad and row of a file gone for good, once the file cache confirms
	 * it. Left when the file is there after all, the row changed, or
	 * Etherpad refused.
	 *
	 * @throws RunBudgetSpentException
	 * @throws EtherpadClientException Etherpad did not answer
	 */
	private function discard(Binding $binding, RunBudget $budget): void {
		if (!$this->bindingService->isFileGone($binding->fileId)) {
			return;
		}
		$context = ['app' => 'etherpad_nextcloud', 'fileId' => $binding->fileId, 'padId' => $binding->padId];
		try {
			$this->padLifecycle->discardIfPresent($binding->padId, $budget, retried: true);
		} catch (RunBudgetSpentException $e) {
			throw $e;
		} catch (\Throwable $e) {
			if (EtherpadClientException::isEtherpadUnreachable($e)) {
				throw $e;
			}
			$this->logger->warning('Could not delete the pad of a file gone for good; it is tried again.', $context + SafeError::context($e));
			return;
		}
		if ($this->bindingService->deleteActiveBinding($binding->fileId, $binding->padId)) {
			$this->logger->info('The file of a pad is gone for good; the pad is deleted.', $context);
		}
	}
}
