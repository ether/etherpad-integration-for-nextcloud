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
 * Works through one group's expired Etherpad sessions: those a public
 * link made, whose visitors share the group whatever author each opens
 * as (ExpiredSessionCollector::noteGroup()). Paced as the collector of an
 * author's is (CollectExpiredSessionsJob), and the argument holds the
 * group's id alone.
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
		return 'Could not queue the next sweep of a group\'s expired Etherpad sessions; the rest waits for another open.';
	}

	protected function gaveUpMessage(): string {
		return 'Gave up a sweep of a group\'s expired Etherpad sessions after three retries without progress; the rest waits for another open.';
	}

	protected function givingUpWarns(): bool {
		return false;
	}
}
