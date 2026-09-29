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
 * (docs/architecture.md, "Files gone for good"). GoneFilesListener makes
 * their rows pending_delete as the request that deleted them runs; a run
 * takes those past their grace whose file the file cache has nothing left
 * of, and deletes pad and row.
 *
 * A file gone without being seen deleted is left alone, pad and row with
 * it: the consistency check lists it.
 *
 * Before a pad goes the file cache is asked once more. A pad Etherpad no
 * longer has counts as gone; one Etherpad refuses to delete is tried again
 * an hour later; Etherpad not answering ends the run. With deleting off
 * nothing is deleted, but the rows keep waiting, so switching it on finds
 * them in place.
 */
class GoneFileSweep {
	/** Rows a batch takes. */
	private const LIMIT = 200;

	/** Batches a run takes at most, within its budget. */
	private const BATCHES = 10;

	/** How long a pad Etherpad refused to delete waits for its next try. */
	private const RETRY_REFUSED_SECONDS = 60 * 60;

	/** How long a file seen deleted for good may still be there before it counts as one whose deletion did not happen. */
	private const STALE_AFTER_SECONDS = 60 * 60;

	public function __construct(
		private BindingService $bindingService,
		private ManagedPadLifecycle $padLifecycle,
		private AppConfigService $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A run, within $budget: the job's own, or the one an admin's settle
	 * promises. An admin's settle ($atOnce) takes the rows still in their
	 * grace too, and those Etherpad refused within the hour: what the job
	 * would do within minutes, or an hour, now - after the admin fixed
	 * Etherpad, say.
	 *
	 * First it makes the rows of files the file cache still has an hour
	 * after they were seen deleted active again: deletions that did not
	 * happen after all.
	 *
	 * @return array{checked: int, deleted: int} rows taken, and pads deleted with their rows
	 */
	public function run(?RunBudget $budget = null, bool $atOnce = false): array {
		$now = $this->timeFactory->getTime();
		$stale = $this->bindingService->clearStaleGone($now - self::STALE_AFTER_SECONDS, self::LIMIT);
		if ($stale > 0) {
			$this->logger->info('Files seen deleted for good are still there an hour later; their deletion did not happen, and they keep their pads.', [
				'app' => 'etherpad_nextcloud',
				'count' => $stale,
			]);
		}
		$summary = ['checked' => 0, 'deleted' => 0];
		if (!$this->appConfig->isDeletePadWithFileEnabled()) {
			return $summary;
		}
		$budget ??= new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS);
		$graceBy = $atOnce ? $now : $now - BindingService::GONE_GRACE_SECONDS;
		// A row this run tried and left - refused, or still in the file cache
		// - is not tried again in it: an admin's settle, which takes rows
		// Etherpad refused within the hour, finds them again in its next
		// batch.
		$tried = [];
		try {
			for ($batch = 0; $batch < self::BATCHES; $batch++) {
				$rows = $this->bindingService->findGone(self::LIMIT, $graceBy, $atOnce ? $now : $now - self::RETRY_REFUSED_SECONDS);
				$new = 0;
				foreach ($rows as $binding) {
					if (isset($tried[$binding->fileId])) {
						continue;
					}
					$tried[$binding->fileId] = true;
					$new++;
					$summary['checked']++;
					if ($this->discard($binding, $budget)) {
						$summary['deleted']++;
					}
				}
				if (count($rows) < self::LIMIT || $new === 0) {
					break;
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
		return $summary;
	}

	/**
	 * The pad and row of a file gone for good, once the file cache confirms
	 * it. Left when the file is there after all, the row changed, or
	 * Etherpad refused - then tried again an hour later, with a warning the
	 * first time.
	 *
	 * @return bool whether pad and row went
	 * @throws RunBudgetSpentException
	 * @throws EtherpadClientException Etherpad did not answer
	 */
	private function discard(Binding $binding, RunBudget $budget): bool {
		if (!$this->bindingService->isFileGone($binding->fileId)) {
			return false;
		}
		$context = ['app' => 'etherpad_nextcloud', 'fileId' => $binding->fileId, 'padId' => $binding->padId];
		try {
			$this->padLifecycle->discardIfPresent($binding->padId, $budget, retried: true);
		} catch (RunBudgetSpentException $e) {
			throw $e;
		} catch (\Throwable $e) {
			// An HTTP error or an answer Etherpad could not have meant reads as
			// Etherpad down, and ends the run - unless Etherpad answers the
			// question it always can: then it is this pad's, and the pad
			// waits its hour rather than hold the head of the queue.
			if (EtherpadClientException::isEtherpadUnreachable($e) && !$this->padLifecycle->answers($budget)) {
				throw $e;
			}
			$this->bindingService->postponeGone($binding->fileId, $binding->padId, $binding->deletedAt);
			$message = 'Could not delete the pad of a file gone for good; it is tried again in an hour.';
			if ($binding->untouchedSinceOwed()) {
				$this->logger->warning($message, $context + SafeError::context($e));
			} else {
				$this->logger->info($message, $context + SafeError::context($e));
			}
			return false;
		}
		if (!$this->bindingService->deleteInState($binding->fileId, $binding->padId, BindingService::STATE_PENDING_DELETE)) {
			return false;
		}
		$this->logger->info('The file of a pad is gone for good; the pad is deleted.', $context);
		return true;
	}
}
