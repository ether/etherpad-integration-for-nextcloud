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
 * Deletes the pads of files seen deleted for good, and only those
 * (docs/architecture.md, "Files gone for good"). GoneFilesListener marks
 * their rows (Binding::$goneAfter) as the request that deleted them runs;
 * a run takes the marked rows whose file the file cache has nothing left
 * of, and deletes pad and row. Active rows only: a row that waits is the
 * older sweep's (PendingBindingService).
 *
 * A file gone without a mark is left alone, pad and row with it: the
 * consistency check lists it.
 *
 * Before a pad goes the file cache is asked once more. A pad Etherpad no
 * longer has counts as gone; one Etherpad refuses to delete is tried again
 * an hour later; Etherpad not answering ends the run. With
 * `delete_on_trash` off nothing is deleted, but the marks are kept, so
 * switching it on finds them in place.
 */
class GoneFileSweep {
	/** Rows a batch takes. */
	private const LIMIT = 200;

	/** Batches a run takes at most, within its budget. */
	private const BATCHES = 10;

	/** How long a pad Etherpad refused to delete waits for its next try. */
	private const RETRY_REFUSED_SECONDS = 60 * 60;

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
		if (!$this->appConfig->isDeleteOnTrashEnabled()) {
			return;
		}
		$budget ??= new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS);
		try {
			for ($batch = 0; $batch < self::BATCHES; $batch++) {
				$rows = $this->bindingService->findMarkedGone(self::LIMIT);
				foreach ($rows as $binding) {
					$this->discard($binding, $budget);
				}
				if (count($rows) < self::LIMIT) {
					return;
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
