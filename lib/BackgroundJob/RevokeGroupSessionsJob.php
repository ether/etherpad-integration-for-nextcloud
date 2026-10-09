<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\GroupSessionRevoker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Takes the rest of one group's live Etherpad sessions after a delete
 * (GroupSessionRevoker). Queued by the delete, per group, under the
 * group's id. It ends when the group holds no live session - looked at
 * once more ten minutes later - when a pad of it is back, or after three
 * delayed retries without progress: then the rest expires on its own.
 */
class RevokeGroupSessionsJob extends SessionSweepJob {
	/**
	 * The second look, for an open under way at the delete that made its
	 * session after the first pass: its calls to Etherpad, a few of up to
	 * fifteen seconds and once more on a retry, end well within this.
	 */
	private const LOOK_AGAIN_AFTER_SECONDS = 600;

	public function __construct(
		ITimeFactory $time,
		private GroupSessionRevoker $revoker,
		IJobList $jobList,
		LoggerInterface $logger,
	) {
		parent::__construct($time, $jobList, $logger);
	}

	protected static function key(): string {
		return 'groupId';
	}

	protected function sweep(string $item): array {
		return $this->revoker->revokeRest($item);
	}

	protected static function lookAgainAfter(): ?int {
		return self::LOOK_AGAIN_AFTER_SECONDS;
	}

	protected function lostPassMessage(): string {
		return 'Could not queue the next revocation of a group\'s Etherpad sessions; the rest expires on its own.';
	}

	protected function gaveUpMessage(): string {
		return 'Gave up revoking a group\'s Etherpad sessions after three retries without progress; the rest expires on its own.';
	}
}
