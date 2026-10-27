<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadKeyRejectedException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Util\PadId;
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
 * an hour later; Etherpad not answering ends the run (unanswered()). With
 * deleting off nothing is deleted, but the rows keep waiting, so switching
 * it on finds them in place.
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

	/** The wait past its due that makes the line of a run Etherpad did not answer at a pad a warning, and the time warnsNow() leaves between two. */
	private const WAITED_WARNING_SECONDS = 60 * 60;

	public function __construct(
		private BindingService $bindingService,
		private ManagedPadLifecycle $padLifecycle,
		private AppConfigService $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private ApiErrorLog $apiErrorLog,
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
		$current = null;
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
					$current = $binding;
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
		} catch (EtherpadKeyRejectedException $e) {
			// The run ends as for an outage, with the line that names the key.
			$this->apiErrorLog->reportIfKeyRejected($e);
		} catch (EtherpadClientException $e) {
			$this->unanswered($e, $current, $now);
		}
		return $summary;
	}

	/**
	 * A warning where the pads of deleted files wait on Etherpad while
	 * nobody opens a pad, whose answer would say Etherpad is down: once the
	 * pad the run met has waited an hour past its due, as warnsNow()
	 * allows. The hour is that pad's wait, not the outage's, so a pad that
	 * waited for other reasons warns at Etherpad's first lapse. Otherwise
	 * info: the next run may get through.
	 */
	private function unanswered(EtherpadClientException $e, ?Binding $binding, int $now): void {
		$context = ['app' => 'etherpad_nextcloud', ...SafeError::context($e)];
		if ($binding !== null && $binding->deletedAt !== null) {
			$waited = $now - ($binding->deletedAt + BindingService::GONE_GRACE_SECONDS);
			if ($waited >= self::WAITED_WARNING_SECONDS && $this->warnsNow($now)) {
				$this->logger->warning('Etherpad did not answer the sweep of files gone for good, and the pad it was deleting has waited an hour or more; it tries again next run.', [
					...$context,
					'fileId' => $binding->fileId,
					'waitedSeconds' => $waited,
				]);
				return;
			}
		}
		$this->logger->info('Etherpad did not answer the sweep of files gone for good; it tries again next run.', $context);
	}

	/**
	 * Whether the sweep warns now: once an hour has passed since it last
	 * warned, and then records the time. It is kept in the app config, which
	 * cron shares with the web whatever the cache. A time later than now -
	 * a clock that ran ahead, a value set by hand - counts as long past:
	 * kept, it would hold every warning back until an hour after it. A
	 * settle and a cron run at once may both warn.
	 */
	private function warnsNow(int $now): bool {
		try {
			$last = $this->appConfig->sweepWarnedAt();
			if ($last <= $now && $now - $last < self::WAITED_WARNING_SECONDS) {
				return false;
			}
			$this->appConfig->recordSweepWarnedAt($now);
		} catch (\Throwable) {
			// The line matters more than the hour: while the app config fails,
			// every run that gets here warns.
		}
		return true;
	}

	/**
	 * The pad and row of a file gone for good, once the file cache confirms
	 * it. Left when the file is there after all, the row changed, or
	 * Etherpad refused - then tried again an hour later, with a warning the
	 * first time. Under an id Etherpad's API does not act on as it is, only
	 * the row goes.
	 *
	 * @return bool whether pad and row went
	 * @throws RunBudgetSpentException
	 * @throws EtherpadClientException Etherpad did not answer, or rejected the key
	 */
	private function discard(Binding $binding, RunBudget $budget): bool {
		if (!$this->bindingService->isFileGone($binding->fileId)) {
			return false;
		}
		if (!PadId::isValid($binding->padId)) {
			// An earlier version bound such ids. Etherpad would refuse the
			// delete, or rewrite the id onto another pad and delete that one
			// (PadId::isValid()). The line leaves the id out: the pad stays,
			// and a public pad's id is its permission.
			if ($this->bindingService->deleteInState($binding->fileId, $binding->padId, BindingService::STATE_PENDING_DELETE)) {
				$this->logger->warning('The file of a pad is gone for good; its row goes, but the pad stays: Etherpad would refuse its id, or take it for another pad.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $binding->fileId,
				]);
			}
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
			if (EtherpadClientException::isEtherpadUnreachable($e) && !$this->answersInTime($budget)) {
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

	/**
	 * Whether Etherpad answers the question it always can, within $budget.
	 * No time left to ask is no answer: ended on the budget instead, a run
	 * whose delete Etherpad left unanswered would say nothing of the pad.
	 */
	private function answersInTime(RunBudget $budget): bool {
		try {
			return $this->padLifecycle->answers($budget);
		} catch (RunBudgetSpentException) {
			return false;
		}
	}
}
