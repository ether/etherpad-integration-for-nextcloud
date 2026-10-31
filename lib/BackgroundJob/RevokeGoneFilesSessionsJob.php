<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\GoneFileSessionRevoker;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Hands over the groups of a batch of files deleted for good
 * (GoneFileSessionRevoker). What a run did not get to comes back without
 * what it did: after a minute while it got further, backed off when
 * something failed or a run got nowhere, and stopped after three delayed
 * retries without progress: then the rest expires on its own.
 */
class RevokeGoneFilesSessionsJob extends QueuedJob {
	/** Seconds to the next run of a batch that got further. */
	private const CONTINUE_DELAY_SECONDS = 60;

	/** Retry delays without progress; progress restarts the backoff. */
	private const RETRY_DELAYS = [60, 300, 900];

	public function __construct(
		ITimeFactory $time,
		private GoneFileSessionRevoker $revoker,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$argument = is_array($argument) ? $argument : [];
		// Stored job arguments may be malformed; what is no file id is left out.
		$fileIds = array_values(array_filter(array_map('intval', (array)($argument['fileIds'] ?? null)), static fn (int $fileId): bool => $fileId > 0));
		if ($fileIds === []) {
			return;
		}
		$attempt = max(0, (int)($argument['attempt'] ?? null));

		$pass = $this->revoker->pass($fileIds);
		if ($pass['left'] === []) {
			return;
		}

		$next = ['fileIds' => $pass['left']];
		try {
			$progress = count($pass['left']) < count($fileIds);
			if ($progress && !$pass['failed']) {
				$this->jobList->scheduleAfter(self::class, $this->time->getTime() + self::CONTINUE_DELAY_SECONDS, $next);
				return;
			}
			$attempt = $progress ? 0 : $attempt;
			if (!isset(self::RETRY_DELAYS[$attempt])) {
				$this->logger->warning('Gave up on the protected pads of files deleted for good after three retries without progress; their sessions expire on their own.', ['app' => 'etherpad_nextcloud', 'files' => count($pass['left'])]);
				return;
			}
			$this->jobList->scheduleAfter(self::class, $this->time->getTime() + self::RETRY_DELAYS[$attempt], $next + ['attempt' => $attempt + 1]);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not queue the next look at files deleted for good; their sessions expire on their own.', ['app' => 'etherpad_nextcloud', 'files' => count($pass['left']), ...SafeError::context($e)]);
		}
	}
}
