<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\ExpiredSessionCollector;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/**
 * Works through one author's expired Etherpad sessions.
 *
 * Queued rather than timed, and per author rather than over everyone: the
 * backlog is noticed while a request is holding the listing anyway, so the
 * work is created exactly where it exists instead of being found again by
 * a sweep over every account on the instance.
 *
 * The author id travels in the argument. Resolving it from the uid would
 * mean reaching back into the session service, and there is no reason to:
 * whoever queued this had it in hand, and the two are one to one.
 *
 * @psalm-api
 */
class CollectExpiredSessionsJob extends QueuedJob {
	public function __construct(
		\OCP\AppFramework\Utility\ITimeFactory $time,
		private ExpiredSessionCollector $collector,
		private IJobList $jobList,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		if (!is_array($argument)) {
			return;
		}
		$uid = (string)($argument['uid'] ?? '');
		$authorId = (string)($argument['authorId'] ?? '');
		if ($uid === '' || $authorId === '') {
			return;
		}

		$result = $this->collector->collect($uid, $authorId);
		if ($result['remaining'] <= 0) {
			return;
		}

		// More than one run's worth. Queued for later rather than looped
		// here: a backlog large enough to need a second pass is large
		// enough that holding a cron worker on it would starve everything
		// behind it, and nothing is waiting for this to finish.
		$this->jobList->scheduleAfter(
			self::class,
			$this->time->getTime() + 60,
			['uid' => $uid, 'authorId' => $authorId],
		);
	}
}
