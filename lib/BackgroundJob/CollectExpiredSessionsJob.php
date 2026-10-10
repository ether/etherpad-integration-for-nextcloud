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
 * Works through one author's expired Etherpad sessions.
 *
 * Queued rather than timed, and per author: an open says who might need
 * collecting, so no sweep over every account is needed. The argument
 * holds the author id, not the uid: job arguments are persisted. A public
 * link's sessions are collected by group as well
 * (CollectExpiredGroupSessionsJob).
 * When there is nothing left, it comes back as the earliest session
 * still standing falls due.
 */
class CollectExpiredSessionsJob extends SessionSweepJob {
	public function __construct(
		ITimeFactory $time,
		private ExpiredSessionCollector $collector,
		IJobList $jobList,
		LoggerInterface $logger,
	) {
		parent::__construct($time, $jobList, $logger);
	}

	protected static function key(): string {
		return 'authorId';
	}

	protected function sweep(string $item): array {
		return $this->collector->collect($item);
	}

	protected function lostPassMessage(): string {
		return 'Could not queue the next Etherpad session sweep; the rest waits for another open.';
	}

	protected function gaveUpMessage(): string {
		return 'Gave up an Etherpad session sweep after three retries without progress; the rest waits for another open.';
	}
}
