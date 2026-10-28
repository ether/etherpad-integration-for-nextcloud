<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Cleans up Nextcloud files and Etherpad pads after partially failed creates.
 * Cleanup steps are isolated and best-effort so cleanup errors do not mask
 * the original create failure.
 *
 * Resolve the captured file id before deletion: a retained File deletes
 * its remembered path, and getId() may read a replacement at that path.
 * Without a claim, leave the file in place.
 */
class PadCreateRollbackService {
	public function __construct(
		private UserNodeResolver $userNodeResolver,
		private LoggerInterface $logger,
		private ProvisionedPadRollback $provisionedPadRollback,
	) {
	}

	public function rollbackFailedCreate(string $uid, PadCreateAttempt $attempt): void {
		$this->rollbackCreatedFileOnly($uid, $attempt);

		if ($attempt->padId() === '') {
			return;
		}
		$this->provisionedPadRollback->discardCreated($attempt, $attempt->claim()?->fileId, 'create');
	}

	/**
	 * Remove only the file, leaving any pad alone: for the creates whose pad
	 * is not this rollback's - a template's, which its materialisation takes
	 * down itself, and an external one's, on another server. Called by name
	 * rather than through rollbackFailedCreate() with no pad recorded, so a
	 * pad step added there cannot reach them.
	 */
	public function rollbackCreatedFileOnly(string $uid, PadCreateAttempt $attempt): void {
		$claim = $attempt->claim();
		if ($claim === null) {
			return;
		}
		$path = $attempt->path();

		try {
			$node = $this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId);
		} catch (NotFoundException) {
			// An unresolved id may still exist outside the user's files; log the skipped cleanup.
			$this->logger->info('Nothing to clean up: the created .pad file did not resolve', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'file' => $path,
				'fileId' => $claim->fileId,
			]);
			return;
		} catch (\Throwable $lookupError) {
			$this->logger->warning('Could not look up the .pad file to clean up', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'file' => $path,
				'fileId' => $claim->fileId,
				...SafeError::context($lookupError),
			]);
			return;
		}

		if (!$this->isStillOurs($node, $path, $claim->writtenHash)) {
			return;
		}

		try {
			$node->delete();
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not cleanup failed .pad file create', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'file' => $path,
				...SafeError::context($cleanupError),
			]);
		}
	}

	/**
	 * Allow deletion only if empty with no recorded write, or matching the
	 * recorded SHA-256. A matching file_id alone does not prove authorship.
	 *
	 * A competing create's empty file is indistinguishable from ours.
	 * This check and the subsequent delete are not atomic.
	 */
	private function isStillOurs(File $node, string $path, ?string $writtenHash): bool {
		try {
			if ((int)$node->getSize() === 0) {
				if ($writtenHash === null) {
					return true;
				}
				$this->logger->warning('Left a .pad file in place: it reads as empty, though this create wrote it', [
					'app' => 'etherpad_nextcloud',
					'file' => $path,
				]);
				return false;
			}
			if ($writtenHash === null) {
				$this->logger->warning('Left a .pad file in place: it has content this create never wrote', [
					'app' => 'etherpad_nextcloud',
					'file' => $path,
				]);
				return false;
			}
			$current = hash('sha256', (string)$node->getContent());
		} catch (\Throwable $readError) {
			$this->logger->warning('Left a .pad file in place because its content could not be read', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				...SafeError::context($readError),
			]);
			return false;
		}

		if (hash_equals($writtenHash, $current)) {
			return true;
		}

		$this->logger->warning('Left a .pad file in place: its content is not what this create wrote', [
			'app' => 'etherpad_nextcloud',
			'file' => $path,
		]);

		return false;
	}
}
