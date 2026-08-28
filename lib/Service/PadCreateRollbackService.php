<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Cleans up Nextcloud files and Etherpad pads after partially failed creates.
 * Cleanup steps are isolated and best-effort so cleanup errors do not mask
 * the original create failure.
 *
 * Cleanup is handed the node the create made, not its path and not its id.
 * A path is not an identity — the file can be renamed while Etherpad is
 * being provisioned, and something else can take the old name — and looking
 * an id up again reopens the same question of whether the thing found is
 * still the thing created. The node is the answer to both: it is the object
 * this request created, in this request.
 */
class PadCreateRollbackService {
	public function __construct(
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	public function rollbackFailedCreate(string $uid, string $path, string $padId, ?File $createdNode): void {
		$this->rollbackCreatedFileOnly($uid, $path, $padId, $createdNode);

		if ($padId !== '') {
			try {
				$this->etherpadClient->deletePad($padId);
			} catch (\Throwable $cleanupError) {
				$this->logger->warning('Could not cleanup failed Etherpad create', [
					'app' => 'etherpad_nextcloud',
					'padId' => $padId,
					'exception' => $cleanupError,
				]);
			}
		}
	}

	/**
	 * Remove only the file, leaving the pad alone.
	 *
	 * For the flows that clean up their own pad — the template
	 * materialisation deletes it before rethrowing — so the full rollback
	 * would delete it a second time, costing a round trip and logging a
	 * warning about a pad that is already gone.
	 */
	public function rollbackCreatedFileOnly(string $uid, string $path, string $padId, ?File $createdNode): void {
		if ($createdNode === null) {
			return;
		}

		try {
			$createdNode->delete();
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not cleanup failed .pad file create', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'file' => $path,
				'exception' => $cleanupError,
			]);
		}
	}

	public function rollbackExternalCreate(string $uid, string $path, ?File $createdNode): void {
		// An external create links a pad that already exists elsewhere, so
		// there is nothing of ours on the Etherpad side to remove.
		$this->rollbackCreatedFileOnly($uid, $path, '', $createdNode);
	}
}
