<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Cleans up Nextcloud files and Etherpad pads after partially failed creates.
 * Cleanup steps are isolated and best-effort so cleanup errors do not mask
 * the original create failure.
 */
class PadCreateRollbackService {
	public function __construct(
		private IRootFolder $rootFolder,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	public function rollbackFailedCreate(string $uid, string $path, string $padId, int $createdFileId): void {
		try {
			if ($createdFileId > 0) {
				$this->deleteCreatedFile($uid, $createdFileId);
			}
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not cleanup failed .pad file create', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'exception' => $cleanupError,
			]);
		}

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

	public function rollbackExternalCreate(string $uid, string $path, int $createdFileId): void {
		try {
			if ($createdFileId > 0) {
				$this->deleteCreatedFile($uid, $createdFileId);
			}
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not cleanup failed external .pad create', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'exception' => $cleanupError,
			]);
		}
	}

	/**
	 * Delete the node this create actually made, identified by its file id.
	 *
	 * Resolving the path again instead would delete whatever holds that name
	 * by the time cleanup runs. A path is not an identity: the file can be
	 * moved or renamed while Etherpad is being provisioned, and the freed
	 * name can be taken by something else — which a later failure would then
	 * delete. The id follows the file wherever it went, and matches nothing
	 * else.
	 */
	private function deleteCreatedFile(string $uid, int $fileId): void {
		if ($fileId <= 0) {
			return;
		}
		// Scoped to the user's own folder, so an id that somehow belongs
		// elsewhere resolves to nothing rather than to someone else's file.
		$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		if ($node instanceof File) {
			$node->delete();
		}
	}
}
