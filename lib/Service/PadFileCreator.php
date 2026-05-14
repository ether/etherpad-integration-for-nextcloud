<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class PadFileCreator {
	public function __construct(
		private IRootFolder $rootFolder,
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
	 * @throws \RuntimeException
	 * @throws PadFileAlreadyExistsException
	 */
	public function createUserFileInFolder(Folder $parent, string $fileName): File {
		// Pre-check first: some Nextcloud storage backends `newFile()` silently
		// return the existing node instead of throwing when the target already
		// exists, which historically let us overwrite the user's file. Detect
		// the collision before we create anything.
		if ($parent->nodeExists($fileName)) {
			throw new PadFileAlreadyExistsException('Target .pad file already exists.');
		}

		try {
			$node = $parent->newFile($fileName);
		} catch (\Throwable $e) {
			// Race: file appeared between the nodeExists check and newFile().
			if ($parent->nodeExists($fileName)) {
				throw new PadFileAlreadyExistsException('Target .pad file already exists.', 0, $e);
			}
			throw new \RuntimeException('Could not create .pad file.', 0, $e);
		}
		if (!$node instanceof File) {
			throw new \RuntimeException('Could not create .pad file.');
		}
		return $node;
	}
}
