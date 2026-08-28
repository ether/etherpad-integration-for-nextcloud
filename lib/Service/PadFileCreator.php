<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Creates the Nextcloud file behind a pad.
 *
 * The serialisation below relies on Nextcloud's locking provider. On an
 * instance with `filelocking.enabled` set to false that provider is a no-op,
 * and creating falls back to check-then-create with the window this class
 * exists to close.
 */
class PadFileCreator {
	public function __construct(
		private IRootFolder $rootFolder,
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws \RuntimeException
	 */
	public function createUserFile(string $uid, string $absolutePath): File {
		$relativePath = ltrim($absolutePath, '/');
		if ($relativePath === '') {
			throw new \RuntimeException('Invalid empty create path.');
		}

		$parentPath = dirname($relativePath);
		$fileName = basename($relativePath);
		// Psalm infers basename() as non-empty-string, but it returns '' for
		// slash-only inputs (e.g. basename('/')), so the empty-string guard is
		// a real defensive check, not dead code.
		/** @psalm-suppress TypeDoesNotContainType */
		if ($fileName === '' || $fileName === '.' || $fileName === '..') {
			throw new \RuntimeException('Invalid target filename.');
		}

		$userFolder = $this->rootFolder->getUserFolder($uid);
		try {
			$parent = $parentPath === '.' ? $userFolder : $userFolder->get($parentPath);
		} catch (NotFoundException $e) {
			throw new \RuntimeException('Target parent folder does not exist.', 0, $e);
		}
		if (!$parent instanceof Folder) {
			throw new \RuntimeException('Target parent folder does not exist.');
		}

		return $this->createUserFileInFolder($parent, $fileName);
	}

	/**
	 * Create the target `.pad`, or refuse because the name is taken.
	 *
	 * Checking and creating are two steps, and between them another request
	 * can take the name. Measured against a real instance, six simultaneous
	 * creates of one name produced six 500s and, twice out of three rounds,
	 * no file at all: the storage refused every one of them, and the retry
	 * inside the catch could not see the winner yet because its cache entry
	 * had not appeared. Nobody got a pad, and nobody got told why.
	 *
	 * So the two steps are serialised on the target name. The loser is
	 * turned away with "that name is taken", which is what actually
	 * happened, instead of a server error.
	 *
	 * @throws \RuntimeException
	 * @throws PadFileAlreadyExistsException
	 */
	public function createUserFileInFolder(Folder $parent, string $fileName): File {
		$lock = $this->lockKey($parent, $fileName);
		try {
			$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException $e) {
			// Another create holds this exact name right now. Whether it
			// succeeds or not, this request may not have it.
			//
			// Logged, because it is not the same thing as the file being
			// there: a request killed between acquire and release leaves the
			// row behind for up to an hour, and the user then sees "that name
			// is taken" for a folder that is empty. Without this line there
			// is nothing to explain it.
			$this->logger->warning('A pad create was refused because the name is locked by another create', [
				'app' => 'etherpad_nextcloud',
				'file' => $fileName,
				'lock' => $lock,
			]);
			throw new PadFileAlreadyExistsException('Target .pad file already exists.', 0, $e);
		}

		try {
			return $this->createInLockedName($parent, $fileName);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @throws \RuntimeException
	 * @throws PadFileAlreadyExistsException
	 */
	private function createInLockedName(Folder $parent, string $fileName): File {
		if ($parent->nodeExists($fileName)) {
			throw new PadFileAlreadyExistsException('Target .pad file already exists.');
		}

		try {
			$node = $parent->newFile($fileName);
		} catch (\Throwable $e) {
			// Something else created it outside our lock — the Files UI, a
			// sync client, another app.
			if ($parent->nodeExists($fileName)) {
				throw new PadFileAlreadyExistsException('Target .pad file already exists.', 0, $e);
			}
			throw new \RuntimeException('Could not create .pad file.', 0, $e);
		}
		if (!$node instanceof File) {
			throw new \RuntimeException('Could not create .pad file.');
		}

		// newFile() is not an exclusive create: Folder::newFile() calls
		// View::touch(), which succeeds on a file that already exists and
		// hands back a node for it. The pre-check above is the actual guard,
		// and this catches what slips past it — a file that appeared in the
		// window and already has content is somebody's, not ours.
		//
		// It is a filter, not a proof. An empty file created in that same
		// window is indistinguishable from one we just made, so the rollback
		// checks again before deleting anything.
		if ((int)$node->getSize() > 0) {
			throw new PadFileAlreadyExistsException('Target .pad file already exists.');
		}

		return $node;
	}

	/**
	 * Keyed on the folder's file id, not its path.
	 *
	 * A shared folder has a different path for its owner than for everyone
	 * it is shared with, so a path-based key would hand the same target two
	 * different locks — and "two people create the same pad at once" is
	 * exactly the case a shared folder makes likely. The file id is the same
	 * number for all of them.
	 *
	 * `oc_file_locks.key` is varchar(64), hence the hash.
	 */
	private function lockKey(Folder $parent, string $fileName): string {
		return Application::APP_ID . ':create:' . substr(sha1($parent->getId() . "\0" . $fileName), 0, 32);
	}
}
