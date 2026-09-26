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
 *   cache has nothing left of: gone for good, so pad and row go. A pad
 *   Etherpad refuses to delete is tried again an hour later.
 * - Slices of the active rows whose file the file cache has, with a cursor
 *   that starts over at the end. It marks a file it finds under a trash
 *   path, which a listener missed, and clears the mark of one back in
 *   Files, so a file that later goes some other way is not taken for one
 *   gone through a trash. A mark younger than a few minutes stays: a move
 *   to the trash marks its files just before it moves them. A path it
 *   cannot place keeps what it has (SweptBinding::isInFiles()). Restores
 *   clear their marks themselves (LeavingPadsListener); this is the net
 *   under them.
 *
 * A file gone without a mark is left alone, pad and row with it.
 *
 * Files deleted past the trash do not wait for a run: the request that
 * deleted them marks and deletes their pads (discardDeleted()), and a run
 * takes what it left.
 *
 * Before a pad goes the file cache is asked once more. A pad Etherpad no
 * longer has counts as gone; Etherpad not answering ends the run's
 * deletions. With `delete_on_trash` off nothing is deleted, but the marks
 * are kept, so switching it on finds them in place.
 */
class GoneFileSweep {
	private const LIMIT = 200;

	/**
	 * Rows a slice of the pass takes. The pass only asks the database, so
	 * with SLICES a run, a full pass over 100,000 rows takes ten runs, under
	 * an hour.
	 *
	 * @var int
	 */
	protected const SLICE = 1000;

	/**
	 * Slices a run takes at most.
	 *
	 * @var int
	 */
	protected const SLICES = 10;

	/** How old a mark must be before the pass clears it for a file in Files. */
	private const MARK_SETTLED_SECONDS = 5 * 60;

	/** How long a pad Etherpad refused to delete waits for its next try. */
	private const RETRY_REFUSED_SECONDS = 60 * 60;

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

	/**
	 * A run, within $budget: the job's own, or what is left of the one an
	 * admin's settle promises.
	 */
	public function run(?RunBudget $budget = null): void {
		$budget ??= new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS);
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
		// At least one slice, which asks only the database, and more while
		// there are more and time is left.
		for ($slice = 0; $slice < static::SLICES; $slice++) {
			if (!$this->passSlice() || $budget->exhausted()) {
				break;
			}
		}
	}

	/**
	 * The pads of the files among $fileIds that a delete has just taken past
	 * the trash, in the request that deleted them (LeavingPadsListener). A
	 * file the trash took is still in the file cache and is passed by. The
	 * gone ones are marked first, while they are surely gone, so what does
	 * not fit in a few seconds, or finds Etherpad not answering, a run
	 * takes. Never throws: the files are gone already, and the delete has
	 * succeeded.
	 *
	 * @param list<int> $fileIds
	 */
	public function discardDeleted(array $fileIds): void {
		try {
			if ($fileIds === []) {
				return;
			}
			$gone = $this->bindingService->findActiveGone($fileIds);
			$this->bindingService->markTrashed(array_map(static fn (Binding $binding): int => $binding->fileId, $gone));
			if (!$this->appConfig->isDeleteOnTrashEnabled()) {
				return;
			}
			$budget = new RunBudget($this->timeFactory, self::REQUEST_SECONDS);
			foreach ($gone as $binding) {
				$this->discard($binding, $budget);
			}
		} catch (RunBudgetSpentException) {
			// The rest is the next run's.
		} catch (\Throwable $e) {
			if (EtherpadClientException::isEtherpadUnreachable($e)) {
				$this->logger->info('Etherpad did not answer for the pads of files deleted past the trash; the sweep tries again.', [
					'app' => 'etherpad_nextcloud',
					...SafeError::context($e),
				]);
				return;
			}
			$this->logger->warning('Could not delete the pads of files deleted past the trash; the sweep tries again.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * One slice of the pass over the active rows whose file the file cache
	 * has, from where the last one stopped. A short slice was the end of
	 * the table: the next starts over.
	 *
	 * @return bool whether there is more after it
	 */
	private function passSlice(): bool {
		$rows = $this->bindingService->findActiveWithFileAfter($this->appConfig->getGoneFileSweepCursor(), static::SLICE);
		$settled = $this->timeFactory->getTime() - self::MARK_SETTLED_SECONDS;
		$mark = [];
		$clear = [];
		$last = 0;
		foreach ($rows as $row) {
			$last = $row->id;
			$trashedAt = $row->binding->trashedAt;
			if ($row->isInTrash() && $trashedAt === null) {
				$mark[] = $row->binding->fileId;
			} elseif ($row->isInFiles() && $trashedAt !== null && $trashedAt < $settled) {
				$clear[] = $row->binding->fileId;
			}
		}
		$this->bindingService->markTrashed($mark);
		$this->bindingService->clearTrashed($clear);
		$more = count($rows) === static::SLICE;
		$this->appConfig->setGoneFileSweepCursor($more ? $last : 0);
		return $more;
	}

	/**
	 * The pad and row of a file gone for good, once the file cache confirms
	 * it. Left when the file is there after all, the row changed, or
	 * Etherpad refused - then tried again an hour later.
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
			$this->bindingService->postponeGone($binding->fileId, $this->timeFactory->getTime() + self::RETRY_REFUSED_SECONDS);
			$this->logger->warning('Could not delete the pad of a file gone for good; it is tried again in an hour.', $context + SafeError::context($e));
			return;
		}
		if ($this->bindingService->deleteActiveBinding($binding->fileId, $binding->padId)) {
			$this->logger->info('The file of a pad is gone for good; the pad is deleted.', $context);
		}
	}
}
