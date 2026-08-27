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
 */
class PadCreateRollbackService {
	public function __construct(
		private UserNodeResolver $userNodeResolver,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	public function rollbackFailedCreate(string $uid, string $path, string $padId, int $createdFileId): void {
		try {
			if ($createdFileId > 0) {
				$this->deleteCreatedFile($uid, $createdFileId, $path, $padId);
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

	/**
	 * Same cleanup, minus the pad: an external create links an existing
	 * remote pad, so there is nothing of ours to delete on the Etherpad
	 * side.
	 */
	public function rollbackExternalCreate(string $uid, string $path, int $createdFileId): void {
		$this->rollbackFailedCreate($uid, $path, '', $createdFileId);
	}

	/**
	 * Delete the node this create actually made, identified by its file id
	 * and confirmed by what is in it.
	 *
	 * Resolving the path again instead would delete whatever holds that name
	 * by the time cleanup runs. A path is not an identity: the file can be
	 * moved or renamed while Etherpad is being provisioned, and the freed
	 * name can be taken by something else — which a later failure would then
	 * delete. The id follows the file wherever it went, and matches nothing
	 * else.
	 */
	private function deleteCreatedFile(string $uid, int $fileId, string $path, string $padId): void {
		if ($fileId <= 0) {
			return;
		}
		$node = $this->userNodeResolver->findUserFileById($uid, $fileId);
		if ($node === null) {
			// Moved out of the user's home, already trashed, owned by someone
			// else, or not visible in the cache yet. Nothing safe to delete —
			// but say so, or the leftover has nothing tying it to this create.
			$this->logger->warning('Could not identify the file to roll back; leaving it in place', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'fileId' => $fileId,
			]);
			return;
		}

		if (!$this->isOurs($node, $padId)) {
			$this->logger->warning('The file to roll back holds someone else\'s content; leaving it in place', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'fileId' => $fileId,
			]);
			return;
		}

		$node->delete();
	}

	/**
	 * Is this still the file this create made?
	 *
	 * Nextcloud has no create-if-absent: Folder::newFile() calls
	 * View::touch(), which succeeds on a file that is already there and
	 * hands back a node for it. So a create that lost a race by microseconds
	 * can be holding a file somebody else made, and its id would then be the
	 * id this rollback deletes by.
	 *
	 * Two things make it ours: nothing has been written yet, or what is
	 * written is the document this create wrote — which names the pad it
	 * provisioned, an id no other file has a reason to carry.
	 */
	private function isOurs(File $node, string $padId): bool {
		try {
			if ((int)$node->getSize() === 0) {
				return true;
			}
			if ($padId === '') {
				return false;
			}
			return str_contains((string)$node->getContent(), $padId);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not read the file to roll back; leaving it in place', [
				'app' => 'etherpad_nextcloud',
				'exception' => $e,
			]);
			return false;
		}
	}
}
