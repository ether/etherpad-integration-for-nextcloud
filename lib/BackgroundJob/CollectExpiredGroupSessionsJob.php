<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\ExpiredSessionCollector;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Collects one group's expired Etherpad sessions, every author's in it.
 * Queued by a public link's open that made a session
 * (ExpiredSessionCollector says why), paced as CollectExpiredSessionsJob,
 * but back for a live session an hour on at the soonest. The argument
 * holds the group's id alone.
 */
class CollectExpiredGroupSessionsJob extends SessionSweepJob {
	public function __construct(
		ITimeFactory $time,
		private ExpiredSessionCollector $collector,
		IJobList $jobList,
		LoggerInterface $logger,
	) {
		parent::__construct($time, $jobList, $logger);
	}

	protected static function key(): string {
		return 'groupId';
	}

	protected function sweep(string $item): array {
		return $this->collector->collectGroup($item);
	}

	protected function lostPassMessage(): string {
		return 'Could not queue the next sweep of a group\'s expired Etherpad sessions; the rest waits until an open queues it again.';
	}

	protected function gaveUpMessage(): string {
		return 'Gave up a sweep of a group\'s expired Etherpad sessions after three retries without progress; the rest waits until an open queues it again.';
	}
}
