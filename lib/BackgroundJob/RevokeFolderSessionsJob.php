<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\FolderSessionRevoker;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Finds the protected pads a delete of their folder did not reach
 * (FolderSessionRevoker), under the folder's id and the last row dealt
 * with. Back at once while it gets further; backed off when something
 * failed or a run got nowhere, and stopped after three delayed retries
 * without progress: then the rest expires on its own.
 */
class RevokeFolderSessionsJob extends QueuedJob {
	/** Retry delays without cursor progress; progress restarts the backoff. */
	private const RETRY_DELAYS = [60, 300, 900];

	public function __construct(
		ITimeFactory $time,
		private FolderSessionRevoker $revoker,
		private IJobList $jobList,
		private LoggerInterface $logger,
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
		$folderId = (int)($argument['folderId'] ?? 0);
		if ($folderId <= 0) {
			return;
		}
		// Stored job arguments may be malformed; negative values start at zero.
		$after = max(0, (int)($argument['after'] ?? 0));
		$attempt = max(0, (int)($argument['attempt'] ?? 0));
		$context = ['app' => 'etherpad_nextcloud', 'fileId' => $folderId];

		$pass = $this->revoker->pass($folderId, $after);
		if ($pass['ended']) {
			return;
		}

		$next = ['folderId' => $folderId, 'after' => $pass['after']];
		try {
			$progress = $pass['after'] > $after;
			if ($progress && !$pass['failed']) {
				$this->jobList->add(self::class, $next);
				return;
			}
			$attempt = $progress ? 0 : $attempt;
			if (!isset(self::RETRY_DELAYS[$attempt])) {
				$this->logger->warning('Gave up looking for a deleted folder\'s protected pads after three retries without progress; their sessions expire on their own.', $context);
				return;
			}
			$this->jobList->scheduleAfter(self::class, $this->time->getTime() + self::RETRY_DELAYS[$attempt], $next + ['attempt' => $attempt + 1]);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not queue the next look for a deleted folder\'s protected pads; their sessions expire on their own.', [...$context, ...SafeError::context($e)]);
		}
	}
}
