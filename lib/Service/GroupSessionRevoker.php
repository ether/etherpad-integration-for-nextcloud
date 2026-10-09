<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGroupSessionsJob;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * The rest of a group's Etherpad sessions, in the background, after a
 * delete took what fit in its request (PadSessionRevoker): what did not
 * fit, what Etherpad did not answer for, and what an open already under
 * way made after the delete listed them.
 *
 * A group loses its sessions while every pad of it is away from Files:
 * its file in a trash, or out of the file cache (BindingService::isInFiles()).
 * A file back in Files keeps them - whoever opens a restored file gets a
 * session that is theirs to keep - and so does a pad of no file, a legacy
 * `.pad`'s neighbour say, which was never a delete's to take.
 */
class GroupSessionRevoker {
	/** Why a group keeps its sessions. */
	public const KEPT_FOR_A_PAD_OF_NO_FILE = 'Left the Etherpad sessions of a group that holds a pad of no file.';
	public const KEPT_FOR_A_FILE_IN_FILES = 'Left the Etherpad sessions of a group with a file in Files.';

	/** Deletes a pass makes at most: a backlog goes on in the next. */
	private const MAX_PER_RUN = 250;

	/** How long after the delete the first pass runs. */
	private const FIRST_PASS_DELAY_SECONDS = 60;

	/** The job is over: the group is gone, or keeps its sessions. */
	private const ENDED = ['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => true];

	/** A pass that could not finish its asking: the backoff comes back, and gives up. */
	private const RETRY = ['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null, 'ended' => false];

	public function __construct(
		private EtherpadClient $etherpadClient,
		private BindingService $bindingService,
		private SessionDeletes $deletes,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Queue a pass for the group, a minute out: an open under way when the
	 * delete listed the sessions has most often made its own by then, and
	 * the job looks once more for one that took longer
	 * (RevokeGroupSessionsJob). Never fails what called it, and costs the
	 * job list's own lookup and one write: a row of the same shape is the
	 * same row, and one queued beside a waiting retry stands down when it
	 * runs (SessionSweepJob).
	 */
	public function queue(string $groupId): void {
		try {
			$this->jobList->scheduleAfter(RevokeGroupSessionsJob::class, $this->timeFactory->getTime() + self::FIRST_PASS_DELAY_SECONDS, ['groupId' => $groupId]);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not queue the revocation of a group\'s remaining Etherpad sessions; they will expire on their own.', [
				'app' => 'etherpad_nextcloud',
				'groupId' => $groupId,
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * One pass over the group's live sessions, the latest to expire first,
	 * within a run's budget and MAX_PER_RUN.
	 *
	 * Before every delete it looks again where the files are - one query
	 * for all of them, the mounts only for a file that moved - since a file
	 * can be restored and opened during the pass, while the sessions are
	 * listed too, and its opener's session would be the first to go.
	 *
	 * The answer is the collector's shape: `remaining` is what it found and
	 * did not reach, `retry` that something failed. `nextDueAt` is always
	 * null: expired sessions are the collector's. `ended` says the job is
	 * over whatever is left - a file back in Files, a pad of no file, the
	 * group gone - with no second look (SessionSweepJob).
	 *
	 * @return array{deleted:int,remaining:int,retry:bool,nextDueAt:null,ended:bool}
	 */
	public function revokeRest(string $groupId): array {
		$budget = new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS);
		$context = ['app' => 'etherpad_nextcloud', 'groupId' => $groupId];

		try {
			$pads = $this->etherpadClient->listPads($groupId, $budget->callTimeout());
		} catch (\Throwable $e) {
			if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				// The group went with its last pad, and its sessions with it.
				return self::ENDED;
			}
			$this->logger->warning('Could not list the pads of a group whose Etherpad sessions to revoke.', [...$context, ...SafeError::context($e)]);
			return self::RETRY;
		}

		try {
			$files = $this->filesOf($pads);
			if ($files === null) {
				$this->logger->info(self::KEPT_FOR_A_PAD_OF_NO_FILE, $context);
				return self::ENDED;
			}
			$places = $this->bindingService->placesOf($files);
			$kept = $this->keptFor($places);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not look up the files of a group\'s pads to revoke its Etherpad sessions.', [...$context, ...SafeError::context($e)]);
			return self::RETRY;
		}
		if ($kept !== null) {
			$this->logger->info($kept, $context);
			return self::ENDED;
		}

		try {
			$sessions = $this->etherpadClient->listSessionsOfGroup($groupId, RunBudget::timeoutOf($budget));
		} catch (RunBudgetSpentException) {
			// No time left to list them: as slow as an outage, and backed off
			// as one, so a pass that keeps running out ends.
			return self::RETRY;
		} catch (\Throwable $e) {
			if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				return self::ENDED;
			}
			$this->logger->warning('Could not list the Etherpad sessions of a group to revoke.', [...$context, ...SafeError::context($e)]);
			return self::RETRY;
		}

		$live = SessionDeletes::live($sessions, $this->timeFactory->getTime());
		// The latest to expire first: most often whoever was at the pad last.
		uasort($live, static fn (array $a, array $b): int => $b['validUntil'] <=> $a['validUntil']);

		$lookupFailed = false;
		$back = null;
		$stillAway = function () use ($files, $context, &$places, &$lookupFailed, &$back): bool {
			try {
				$now = $this->bindingService->placesOf($files);
				if ($now === $places) {
					return true;
				}
				$places = $now;
				$back = $this->keptFor($now);
				return $back === null;
			} catch (\Throwable $e) {
				$this->logger->warning('Could not look up the files of a group\'s pads to revoke its Etherpad sessions.', [...$context, ...SafeError::context($e)]);
				$lookupFailed = true;
				return false;
			}
		};
		$run = $this->deletes->within($budget, array_keys($live), self::MAX_PER_RUN, ['groupId' => $groupId], 'Could not revoke an Etherpad session of a group.', $stillAway);

		if ($run['deleted'] > 0) {
			$this->logger->info('Revoked remaining Etherpad sessions of a group.', [
				...$context,
				'count' => $run['deleted'],
				'remaining' => $run['stopped'] ? 0 : count($live) - $run['handled'],
			]);
		}
		if ($run['stopped']) {
			if ($back !== null) {
				$this->logger->info($back, $context);
			}
			// A file back ends the job; a lookup that failed asks again.
			return ['deleted' => $run['deleted'], 'remaining' => 0, 'retry' => $lookupFailed, 'nextDueAt' => null, 'ended' => !$lookupFailed];
		}

		return ['deleted' => $run['deleted'], 'remaining' => count($live) - $run['handled'], 'retry' => $run['refused'], 'nextDueAt' => null, 'ended' => false];
	}

	/**
	 * The files of the pads in $pads, or null for a pad of no file. Rows owed
	 * a delete among them: a file seen again makes one active.
	 *
	 * @param list<string> $pads
	 * @return ?list<int>
	 */
	private function filesOf(array $pads): ?array {
		$files = [];
		foreach ($pads as $padId) {
			$binding = $this->bindingService->findByPadId($padId);
			if ($binding === null) {
				return null;
			}
			$files[] = $binding->fileId;
		}

		return $files;
	}

	/**
	 * KEPT_FOR_A_FILE_IN_FILES when one of the files at $places is in Files,
	 * else null. A file the file cache does not have is not among them.
	 *
	 * @param array<int,array{int,string}> $places
	 */
	private function keptFor(array $places): ?string {
		foreach ($places as [$storage, $path]) {
			if ($this->bindingService->isInFiles($storage, $path)) {
				return self::KEPT_FOR_A_FILE_IN_FILES;
			}
		}

		return null;
	}
}
