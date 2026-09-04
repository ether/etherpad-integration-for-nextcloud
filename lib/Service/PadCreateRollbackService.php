<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

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
		private ManagedPadLifecycle $padLifecycle,
		private UserNodeResolver $userNodeResolver,
		private LoggerInterface $logger,
	) {
	}

	public function rollbackFailedCreate(string $uid, string $path, string $padId, ?CreatedFileClaim $claim): void {
		$this->rollbackCreatedFileOnly($uid, $path, $claim);

		if ($padId !== '') {
			try {
				// A failed create, so this pad was provisioned by the same
				// request — its group needs no ownership check, and must not
				// wait on one, because nothing retries a rollback.
				$this->padLifecycle->discardProvisioned($padId);
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
	public function rollbackCreatedFileOnly(string $uid, string $path, ?CreatedFileClaim $claim): void {
		if ($claim === null) {
			return;
		}

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
				'exception' => $lookupError,
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
				'exception' => $cleanupError,
			]);
		}
	}

	public function rollbackExternalCreate(string $uid, string $path, ?CreatedFileClaim $claim): void {
		// An external create links a pad that already exists elsewhere, so
		// there is nothing of ours on the Etherpad side to remove.
		$this->rollbackCreatedFileOnly($uid, $path, $claim);
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
				return $writtenHash === null;
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
				'exception' => $readError,
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
