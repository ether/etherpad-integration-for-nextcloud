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
	 * The file with this id as this user can reach it, or null.
	 *
	 * Deliberately not restricted to files the user owns. A pad created in a
	 * folder someone shared with them belongs, at storage level, to the
	 * person who shared it — and in a group folder there may be no owner at
	 * all. An ownership test would refuse to clean up exactly those creates.
	 * What makes the file safe to delete is decided by the caller, on the
	 * file's contents; see PadCreateRollbackService.
	 *
	 * Returns null instead of throwing: the caller is cleanup, and "not
	 * reachable" and "not there" lead to the same decision.
	 */
	public function findUserFileById(string $uid, int $fileId): ?File {
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
