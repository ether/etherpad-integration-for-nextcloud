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
 * The file is deleted by id, never through the node the create returned.
 * A `File` object is not an identity: `File::delete()` unlinks
 * `$this->view->unlink($this->path)`, so it acts on whatever sits at the
 * remembered path at that moment. Etherpad provisioning takes long enough
 * for the file to be moved and the name taken by something else, and the
 * rollback would then delete a stranger's file. Re-resolving the id gives a
 * fresh path for the file we actually created — and when the id resolves to
 * nothing, there is nothing of ours left to remove.
 */
class PadCreateRollbackService {
	public function __construct(
		private ManagedPadLifecycle $padLifecycle,
		private UserNodeResolver $userNodeResolver,
		private LoggerInterface $logger,
	) {
	}

	public function rollbackFailedCreate(string $uid, string $path, string $padId, ?File $createdNode): void {
		$this->rollbackCreatedFileOnly($uid, $path, $createdNode);

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
	public function rollbackCreatedFileOnly(string $uid, string $path, ?File $createdNode): void {
		if ($createdNode === null) {
			return;
		}

		$fileId = $this->createdFileId($createdNode);
		if ($fileId === null) {
			// No id, no proof of which file this is. Leaving a stray empty
			// `.pad` behind is a mess; deleting the wrong file is a loss.
			$this->logger->warning('Skipped .pad cleanup because the created file has no usable id', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'file' => $path,
			]);
			return;
		}

		try {
			$this->userNodeResolver->resolveUserFileNodeById($uid, $fileId)->delete();
		} catch (NotFoundException) {
			// Already gone, or no longer this user's. Either way there is
			// nothing of ours to remove, and nothing to report.
			return;
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not cleanup failed .pad file create', [
				'app' => 'etherpad_nextcloud',
				'uid' => $uid,
				'file' => $path,
				'exception' => $cleanupError,
			]);
		}
	}

	private function createdFileId(File $createdNode): ?int {
		try {
			$fileId = $createdNode->getId();
		} catch (\Throwable) {
			return null;
		}

		return $fileId > 0 ? $fileId : null;
	}

	public function rollbackExternalCreate(string $uid, string $path, ?File $createdNode): void {
		// An external create links a pad that already exists elsewhere, so
		// there is nothing of ours on the Etherpad side to remove.
		$this->rollbackCreatedFileOnly($uid, $path, $createdNode);
	}
}
