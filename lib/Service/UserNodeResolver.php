<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class UserNodeResolver {
	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @throws NotFoundException
	 */
	public function resolveUserFileNodeById(string $uid, int $fileId): File {
		$nodes = $this->rootFolder->getById($fileId);
		$prefix = '/' . $uid . '/files/';
		foreach ($nodes as $node) {
			if (!$node instanceof File) {
				continue;
			}
			if (str_starts_with((string)$node->getPath(), $prefix)) {
				return $node;
			}
		}

		throw new NotFoundException('File not found by ID.');
	}

	/**
	 * The user's own root counts as one of their folders. Its path is
	 * `/<uid>/files` with no trailing slash, so a prefix test alone rejects
	 * it — and create-by-parent then cannot create a pad directly in "All
	 * files", which is where someone is most likely to start.
	 *
	 * @throws NotFoundException
	 */
	public function resolveUserFolderNodeById(string $uid, int $folderId): Folder {
		$nodes = $this->rootFolder->getById($folderId);
		$root = '/' . $uid . '/files';
		foreach ($nodes as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			$path = rtrim((string)$node->getPath(), '/');
			// The separator stays in the prefix test so a sibling that merely
			// starts the same way — /<uid>/files_versions — is still refused.
			if ($path === $root || str_starts_with($path, $root . '/')) {
				return $node;
			}
		}

		throw new NotFoundException('Folder not found by ID.');
	}

	/**
	 * @throws NotFoundException
	 */
	public function resolveUserFileNodeByPath(string $uid, string $absolutePath): File {
		$relativePath = ltrim($absolutePath, '/');
		if ($relativePath === '') {
			throw new NotFoundException('Invalid empty file path.');
		}

		$userFolder = $this->rootFolder->getUserFolder($uid);
		$node = $userFolder->get($relativePath);
		if (!$node instanceof File) {
			throw new NotFoundException('Path does not reference a file.');
		}

		return $node;
	}

	/**
	 * The user's *own* file with this id, or null.
	 *
	 * Ownership is checked rather than assumed. Incoming shares are mounted
	 * inside the user's folder, so an id can resolve to a node someone else
	 * owns — and deleting that removes the owner's file, not a copy of it.
	 * The path prefix alone does not catch this, because a share mounts
	 * under `/<uid>/files/` like everything else.
	 *
	 * Returns null instead of throwing: the caller is cleanup, and "not
	 * ours" and "not there" lead to the same decision.
	 */
	public function findOwnedUserFileById(string $uid, int $fileId): ?File {
		if ($fileId <= 0) {
			return null;
		}
		$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		if (!$node instanceof File) {
			return null;
		}
		if (!str_starts_with((string)$node->getPath(), '/' . $uid . '/files/')) {
			return null;
		}
		$owner = $node->getOwner();
		if ($owner === null || $owner->getUID() !== $uid) {
			return null;
		}

		return $node;
	}

	/**
	 * @throws NotFoundException
	 */
	public function toUserAbsolutePath(string $uid, File $node): string {
		$nodePath = (string)$node->getPath();
		$prefix = '/' . $uid . '/files/';
		if (!str_starts_with($nodePath, $prefix)) {
			throw new NotFoundException('Cannot map file to user file tree.');
		}

		return '/' . ltrim(substr($nodePath, strlen($prefix)), '/');
	}
}
