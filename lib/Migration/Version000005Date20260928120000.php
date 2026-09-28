<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use Closure;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * From 1.1.0-beta.1, whose trash deleted a file's pad, to a trash that
 * keeps it (docs/deleting-pads.md).
 *
 * A trash that could not reach Etherpad left its row `pending_delete`, the
 * pad's deletion owed. Whose file the file cache still has - in a trash, or
 * back in Files - is an active row again: its pad was kept, and the trash
 * keeps it now. Whose file is gone for good stays, and the sweep of files
 * gone for good takes its pad.
 *
 * The setting that said whether a trash deleted pads, `delete_on_trash`,
 * now says whether a file deleted for good takes its pad along:
 * `delete_pad_with_file`, with the value the admin gave the old one.
 *
 * Its three jobs that retried the trash's deletions are gone. Taken off
 * the job list here, rather than left to the first cron run, which drops
 * a job whose class is gone with a warning each.
 *
 * @psalm-api
 */
class Version000005Date20260928120000 extends SimpleMigrationStep {
	/** The jobs 1.1.0-beta.1 added, whose classes are gone. */
	private const GONE_JOBS = [
		'OCA\\EtherpadNextcloud\\BackgroundJob\\HotPendingDeleteRetryJob',
		'OCA\\EtherpadNextcloud\\BackgroundJob\\WarmPendingDeleteRetryJob',
		'OCA\\EtherpadNextcloud\\BackgroundJob\\ColdPendingDeleteRetryJob',
	];

	public function __construct(
		private BindingService $bindingService,
		private ITimeFactory $timeFactory,
		private AppConfigService $appConfig,
		private IJobList $jobList,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->appConfig->takeOverDeleteOnTrash();
		foreach (self::GONE_JOBS as $job) {
			/** @psalm-suppress ArgumentTypeCoercion The classes are gone, which is why they go; the job list removes their rows by the name alone. */
			$this->jobList->remove($job);
		}
		// A pending_delete row whose file the file cache still has is the
		// file's, kept: the same step the sweep takes for a deletion that
		// did not happen, for every such row at once.
		$now = $this->timeFactory->getTime();
		while ($this->bindingService->clearStaleGone($now, 500) === 500) {
		}
	}
}
