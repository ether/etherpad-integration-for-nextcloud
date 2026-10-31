<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Resumes work stored in the job argument. Progress schedules another pass
 * after a minute. Failures or no progress trigger backoff; three retries
 * without progress stop the job.
 */
abstract class ResumingJob extends QueuedJob {
	/** Seconds to the next run of a job that got further. */
	private const CONTINUE_DELAY_SECONDS = 60;

	/** Retry delays without progress; progress restarts the backoff. */
	private const RETRY_DELAYS = [60, 300, 900];

	public function __construct(
		ITimeFactory $time,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * Returns null to stop the job. `next` stores remaining work; `progress`
	 * resets retry backoff; `failed` requests backoff even after progress;
	 * `context` contains log fields.
	 *
	 * @param array<array-key,mixed> $argument stored data, which may be malformed
	 * @return ?array{next:array<string,mixed>,progress:bool,failed:bool,context:array<string,mixed>}
	 */
	abstract protected function pass(array $argument): ?array;

	/** What the log says when the job gives up after its retries. */
	abstract protected function gaveUpMessage(): string;

	/** What the log says when the next run cannot be queued. */
	abstract protected function lostRunMessage(): string;

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$argument = is_array($argument) ? $argument : [];
		// A negative attempt would index past the table.
		$attempt = max(0, (int)($argument['attempt'] ?? null));

		$pass = $this->pass($argument);
		if ($pass === null) {
			return;
		}

		try {
			if ($pass['progress'] && !$pass['failed']) {
				$this->jobList->scheduleAfter(static::class, $this->time->getTime() + self::CONTINUE_DELAY_SECONDS, $pass['next']);
				return;
			}
			$attempt = $pass['progress'] ? 0 : $attempt;
			if (!isset(self::RETRY_DELAYS[$attempt])) {
				$this->logger->warning($this->gaveUpMessage(), ['app' => 'etherpad_nextcloud', ...$pass['context']]);
				return;
			}
			$this->jobList->scheduleAfter(static::class, $this->time->getTime() + self::RETRY_DELAYS[$attempt], $pass['next'] + ['attempt' => $attempt + 1]);
		} catch (\Throwable $e) {
			$this->logger->warning($this->lostRunMessage(), ['app' => 'etherpad_nextcloud', ...$pass['context'], ...SafeError::context($e)]);
		}
	}
}
