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
 * - A slice of every active row, with a cursor that starts over at the
 *   end. It marks a file it finds under a trash path and clears the mark of
 *   one back in Files. A file missing without a mark starts a grace period
 *   (Binding::$missingSince); after it, pad and row go too - unless the
 *   brake holds: more such files since an admin last released it than the
 *   threshold, as when the file cache was lost. The brake is checked, and
 *   warned of, every slice, so it holds as the files go missing and an
 *   admin has the grace period to look.
 *
 * Before a pad goes the file cache is asked once more. A pad Etherpad no
 * longer has counts as gone; Etherpad not answering ends the run. With
 * `delete_on_trash` off nothing is deleted, but marks, dates and the brake
 * are kept, so switching it on finds them in place.
 */
class GoneFileSweep {
	private const LIMIT = 200;

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
		$deleting = $this->appConfig->isDeleteOnTrashEnabled();
		try {
			if ($deleting) {
				foreach ($this->bindingService->findMarkedGone(self::LIMIT) as $binding) {
					$this->discard($binding, $budget, false);
				}
			}
			$this->passSlice($budget, $deleting);
		} catch (RunBudgetSpentException) {
			// The rest waits for the next run.
		} catch (EtherpadClientException $e) {
			$this->logger->info('Etherpad did not answer the sweep of files gone for good; it tries again next run.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * One slice of the pass over every active row, from where the last one
	 * stopped. The dates first, then the brake, so files going missing in
	 * this slice count, then the pads past their grace period. The cursor
	 * moves once the slice is done; a slice cut short is taken again.
	 *
	 * @throws RunBudgetSpentException
	 * @throws EtherpadClientException Etherpad did not answer
	 */
	private function passSlice(RunBudget $budget, bool $deleting): void {
		$rows = $this->bindingService->findActiveAfter($this->appConfig->getGoneFileSweepCursor(), self::LIMIT);
		$graceEnded = $this->timeFactory->getTime() - $this->appConfig->getGoneFileGraceSeconds();
		$pastGrace = [];
		$last = 0;
		foreach ($rows as $row) {
			$last = $row->id;
			$binding = $row->binding;
			if ($row->filePath === null) {
				if ($binding->trashedAt !== null) {
					// Gone for good: the first pass's.
				} elseif ($binding->missingSince === null) {
					$this->bindingService->markMissing($binding->fileId);
				} elseif ($binding->missingSince <= $graceEnded) {
					$pastGrace[] = $binding;
				}
				continue;
			}
			if ($row->isInTrash() && $binding->trashedAt === null) {
				$this->bindingService->markTrashed($binding->fileId);
			} elseif (!$row->isInTrash() && $binding->trashedAt !== null) {
				$this->bindingService->clearTrashed($binding->fileId);
			}
			if ($binding->missingSince !== null) {
				$this->bindingService->clearMissing($binding->fileId);
			}
		}
		if (!$this->brakeHolds() && $deleting) {
			foreach ($pastGrace as $binding) {
				$this->discard($binding, $budget, true);
			}
		}
		// A short slice was the end of the table: the next starts over.
		$this->appConfig->setGoneFileSweepCursor(count($rows) < self::LIMIT ? 0 : $last);
	}

	/**
	 * Whether more files went missing without a mark since the brake's last
	 * release than the threshold. Kept in the app config for the admin, and
	 * warned of once, when it starts to hold.
	 */
	private function brakeHolds(): bool {
		$threshold = $this->appConfig->getGoneFileBrakeThreshold();
		$holds = $this->bindingService->countMissingSince($this->appConfig->getGoneFileBrakeReleasedAt(), $threshold + 1) > $threshold;
		if ($holds !== $this->appConfig->isGoneFileBrakeEngaged()) {
			$this->appConfig->setGoneFileBrakeEngaged($holds);
			if ($holds) {
				$this->logger->warning('Many .pad files went missing at once without passing a trash; their pads are kept until an admin releases the brake.', [
					'app' => 'etherpad_nextcloud',
					'threshold' => $threshold,
				]);
			}
		}
		return $holds;
	}

	/**
	 * The pad and row of a file gone for good, once the file cache confirms
	 * it. Left when the file is there after all, the row changed, or
	 * Etherpad refused.
	 *
	 * @throws RunBudgetSpentException
	 * @throws EtherpadClientException Etherpad did not answer
	 */
	private function discard(Binding $binding, RunBudget $budget, bool $afterGrace): void {
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
		if (!$this->bindingService->deleteActiveBinding($binding->fileId, $binding->padId)) {
			return;
		}
		if ($afterGrace) {
			$this->logger->warning('The file of a pad has been missing past the grace period without passing a trash; the pad is deleted.', $context);
		} else {
			$this->logger->info('The file of a pad is gone for good; the pad is deleted.', $context);
		}
	}
}
