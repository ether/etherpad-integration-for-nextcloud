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
			// The create never got an id it could trust, so there is nothing
			// here that identifies a file. Leaving a stray empty `.pad`
			// behind is a mess; deleting the wrong file is a loss.
			return;
		}

		try {
			$node = $this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId);
		} catch (NotFoundException) {
			// Usually the file is simply gone. But the same exception covers
			// an id that now resolves to a folder or outside the user's
			// files, and in those a file this request created is still
			// there — so this is worth a line, unlike a silent success.
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
	 * The id is not proof of authorship, and neither is a valid pad
	 * document.
	 *
	 * `newFile()` can hand back a file another writer created a moment
	 * earlier, empty, which every check at create time accepts. That writer
	 * may then have finished its own create into the same file: its
	 * document carries the same `file_id` — the id is the file's, not the
	 * attempt's — and only its `pad_id` differs. So the test is against
	 * what *this* attempt wrote: the file is removed while it is still
	 * untouched, or while it still holds exactly those bytes.
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
