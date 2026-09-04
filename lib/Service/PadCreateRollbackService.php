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
 * Cleanup is addressed by the file id the create read once, at the moment it
 * created the file — never by a `File` object and never by asking one for
 * its id again. `File::delete()` unlinks its remembered path and
 * `Node::getId()` re-reads through that same path, so a node held across pad
 * provisioning answers for whatever occupies the path by then. An id that
 * was never read stays unknown, and an unknown id deletes nothing.
 */
class PadCreateRollbackService {
	public function __construct(
		private PadFileService $padFileService,
		private ManagedPadLifecycle $padLifecycle,
		private UserNodeResolver $userNodeResolver,
		private LoggerInterface $logger,
	) {
	}

	public function rollbackFailedCreate(string $uid, string $path, string $padId, ?int $createdFileId): void {
		$this->rollbackCreatedFileOnly($uid, $path, $createdFileId);

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
	public function rollbackCreatedFileOnly(string $uid, string $path, ?int $createdFileId): void {
		if ($createdFileId === null || $createdFileId <= 0) {
			// The create never got an id it could trust, so there is nothing
			// here that identifies a file. Leaving a stray empty `.pad`
			// behind is a mess; deleting the wrong file is a loss.
			return;
		}

		try {
			$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $createdFileId);
		} catch (NotFoundException) {
			// Already gone, or no longer this user's. Nothing of ours to
			// remove, and nothing to report.
			return;
		} catch (\Throwable $lookupError) {
			$this->logger->warning('Could not look up the .pad file to clean up', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'file' => $path,
				'fileId' => $createdFileId,
				'exception' => $lookupError,
			]);
			return;
		}

		if (!$this->isStillOurs($node, $createdFileId, $path)) {
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

	public function rollbackExternalCreate(string $uid, string $path, ?int $createdFileId): void {
		// An external create links a pad that already exists elsewhere, so
		// there is nothing of ours on the Etherpad side to remove.
		$this->rollbackCreatedFileOnly($uid, $path, $createdFileId);
	}

	/**
	 * The id is not proof of authorship.
	 *
	 * `newFile()` can hand back a file another writer created a moment
	 * earlier, empty, which every check at create time accepts — and that
	 * writer may have filled it while this request was away at Etherpad.
	 * So the file is only removed while it is still empty, or while it
	 * carries the document this create wrote into it, which names the id.
	 */
	private function isStillOurs(File $node, int $fileId, string $path): bool {
		try {
			if ((int)$node->getSize() === 0) {
				return true;
			}
			$content = (string)$node->getContent();
		} catch (\Throwable $readError) {
			$this->logger->warning('Left a .pad file in place because its content could not be read', [
				'app' => 'etherpad_nextcloud',
				'uid' => $path,
				'fileId' => $fileId,
				'exception' => $readError,
			]);
			return false;
		}

		try {
			$writtenFileId = (int)($this->padFileService->readPad($content)->frontmatter['file_id'] ?? 0);
		} catch (\Throwable) {
			$writtenFileId = 0;
		}
		if ($writtenFileId === $fileId) {
			return true;
		}

		$this->logger->warning('Left a .pad file in place: it has content this create did not write', [
			'app' => 'etherpad_nextcloud',
			'file' => $path,
			'fileId' => $fileId,
		]);

		return false;
	}
}
