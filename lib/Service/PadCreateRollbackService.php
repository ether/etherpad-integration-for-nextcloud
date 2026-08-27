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
		$this->rollbackCreatedFileOnly($uid, $path, $padId, $createdFileId);

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
	 * For the flows that already clean up their own pad — the template
	 * materialisation deletes it before rethrowing — so calling the full
	 * rollback would delete it a second time, costing an API round trip and
	 * logging a cleanup warning about a pad that is already gone.
	 *
	 * The pad id is still needed here: it is what proves the file was
	 * written by this create.
	 */
	public function rollbackCreatedFileOnly(string $uid, string $path, string $padId, int $createdFileId): void {
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
			$this->logger->warning('Could not confirm the file to roll back was written by this create; leaving it in place', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'fileId' => $fileId,
			]);
			return;
		}

		$node->delete();
	}

	/**
	 * Is this file demonstrably the one this create wrote?
	 *
	 * Only one thing demonstrates it: the document names the pad this create
	 * provisioned, an id nothing else has a reason to carry.
	 *
	 * An empty file is deliberately *not* enough. Nextcloud has no
	 * create-if-absent — Folder::newFile() calls View::touch(), which
	 * succeeds on a file that is already there and hands back a node for it
	 * — so a create that lost a race by microseconds can be holding an empty
	 * file somebody else just made. Nothing afterwards can tell the two
	 * apart, and this method decides whether to delete permanently.
	 *
	 * The cost is an empty .pad left behind when a create fails before it
	 * writes anything. That is a file the user can delete; the alternative
	 * is deleting a file they made.
	 */
	private function isOurs(File $node, string $padId): bool {
		if ($padId === '') {
			return false;
		}
		try {
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
