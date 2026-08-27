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

class PadFileCreator {
	public function __construct(
		private IRootFolder $rootFolder,
		private ILockingProvider $lockingProvider,
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

		// Some storage backends answer newFile() with the existing node
		// instead of throwing. The caller records this node's id and the
		// rollback deletes by it, so handing back someone's existing file
		// would let a failed create delete their data. A file we just made
		// is empty; anything else is not ours to take.
		if ((int)$node->getSize() > 0) {
			throw new PadFileAlreadyExistsException('Target .pad file already exists.');
		}

		return $node;
	}

	/**
	 * `oc_file_locks.key` is varchar(64), so the folder path and name are
	 * hashed rather than spelled out — a deep path with a long name would
	 * otherwise be truncated, and two different targets could collide on one
	 * lock.
	 */
	private function lockKey(Folder $parent, string $fileName): string {
		return Application::APP_ID . ':create:' . substr(sha1($parent->getPath() . "\0" . $fileName), 0, 32);
	}
}
