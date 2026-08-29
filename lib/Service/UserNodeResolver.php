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
	 * Resolved through the user's own folder, not the global root.
	 *
	 * getUserFolder() is what sets the user's filesystem up and registers
	 * their mount providers; asking the global root for an id before that
	 * has happened answers "no such file" for a pad on external storage, in
	 * a groupfolder, or in a share whose mount this request has not touched
	 * — while the same file resolves by path, which goes through
	 * getUserFolder() already. The two halves have to be equally strong:
	 * the viewer no longer retries by path when opening by id fails, so a
	 * by-id lookup that is merely weaker is now a dead end.
	 *
	 * Scoping to the user folder also makes the answer theirs by
	 * construction. The prefix test stays as a second pair of eyes.
	 *
	 * @throws NotFoundException
	 */
	public function resolveUserFileNodeById(string $uid, int $fileId): File {
		$nodes = $this->userFolder($uid)->getById($fileId);
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
	 * The user's own root counts as one of their folders, and it is the one
	 * folder a lookup inside that folder cannot find — so it is answered by
	 * its id before the search. Without that, create-by-parent could not put
	 * a pad in "All files", which is where someone is most likely to start.
	 *
	 * @throws NotFoundException
	 */
	public function resolveUserFolderNodeById(string $uid, int $folderId): Folder {
		// Same reasoning as resolveUserFileNodeById: through the user's own
		// folder, so their mounts exist before the id is looked up. The
		// folder itself is answered directly — asking a folder for its own
		// id is not something Folder::getById() is required to answer.
		$userFolder = $this->userFolder($uid);
		if ($userFolder->getId() === $folderId) {
			return $userFolder;
		}
		$nodes = $userFolder->getById($folderId);
		$root = '/' . $uid . '/files';
		foreach ($nodes as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			$path = rtrim((string)$node->getPath(), '/');
			// The separator stays in the prefix test so a sibling that merely
			// starts the same way — /<uid>/files_versions — is still refused.
			// The root itself is not compared here: getById() searches inside
			// the folder and does not return it, which is why it is answered
			// by id above.
			if (str_starts_with($path, $root . '/')) {
				return $node;
			}
		}

		throw new NotFoundException('Folder not found by ID.');
	}

	/**
	 * The user's folder, or NotFoundException.
	 *
	 * getUserFolder() answers NoUserException and NotPermittedException,
	 * neither of which is a NotFoundException — and the callers of this
	 * class catch that one type to degrade gracefully: a metadata lookup
	 * answers "not a pad", the legacy migration reports a collision. Left
	 * to escape, an unavailable home storage would turn both into a 500,
	 * where asking the global root simply returned no nodes. Not being able
	 * to look inside a user's files is, for every caller here, the same
	 * answer as not finding the file.
	 *
	 * Caught as `\Exception` because the types cannot all be named. The
	 * public interface declares NotPermittedException, which is in OCP —
	 * but the other one it declares, NoUserException, lives in `OC\User\`
	 * and is not part of the public API an app may reference. Naming only
	 * the reachable half would leave the more likely failure uncaught, and
	 * this wraps a single call whose only outcomes are a folder or a
	 * failure to produce one.
	 *
	 * @throws NotFoundException
	 */
	private function userFolder(string $uid): Folder {
		try {
			return $this->rootFolder->getUserFolder($uid);
		} catch (\Exception $e) {
			throw new NotFoundException('Cannot access the user file tree.', 0, $e);
		}
	}

	/**
	 * @throws NotFoundException
	 */
	public function resolveUserFileNodeByPath(string $uid, string $absolutePath): File {
		$relativePath = ltrim($absolutePath, '/');
		if ($relativePath === '') {
			throw new NotFoundException('Invalid empty file path.');
		}

		$node = $this->userFolder($uid)->get($relativePath);
		if (!$node instanceof File) {
			throw new NotFoundException('Path does not reference a file.');
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
