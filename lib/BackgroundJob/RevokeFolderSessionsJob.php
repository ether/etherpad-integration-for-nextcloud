<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\BackgroundJob;

use OCA\EtherpadNextcloud\Service\FolderSessionRevoker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Finds the protected pads a delete of their folder did not reach
 * (FolderSessionRevoker), under the folder's id and the last row dealt with.
 */
class RevokeFolderSessionsJob extends ResumingJob {
	public function __construct(
		ITimeFactory $time,
		private FolderSessionRevoker $revoker,
		IJobList $jobList,
		LoggerInterface $logger,
	) {
		parent::__construct($time, $jobList, $logger);
	}

	protected function pass(array $argument): ?array {
		$folderId = (int)($argument['folderId'] ?? null);
		if ($folderId <= 0) {
			return null;
		}
		// Stored job arguments may be malformed; a negative row starts at zero.
		$after = max(0, (int)($argument['after'] ?? null));

		$pass = $this->revoker->pass($folderId, $after);
		if ($pass['ended']) {
			return null;
		}
		return [
			'next' => ['folderId' => $folderId, 'after' => $pass['after']],
			'progress' => $pass['after'] > $after,
			'failed' => $pass['failed'],
			'context' => ['fileId' => $folderId],
		];
	}

	protected function gaveUpMessage(): string {
		return 'Gave up looking for a deleted folder\'s protected pads after three retries without progress; their sessions expire on their own.';
	}

	protected function lostRunMessage(): string {
		return 'Could not queue the next look for a deleted folder\'s protected pads; their sessions expire on their own.';
	}
}
