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
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

class UserNodeResolver {
	/**
	 * Thrown by IRootFolder::getUserFolder(), and not referenceable: it is
	 * an `OC\` class, so it is matched by name rather than in a catch.
	 */
	private const NO_USER_EXCEPTION = 'OC\\User\\NoUserException';

	public function __construct(
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
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
	/**
	 * The user's own view of a file id, preferring a path they may write.
	 *
	 * One file can be reachable by several paths at once — shared to this
	 * user directly and again inside a shared folder — and the two can carry
	 * different permissions. Taking whichever came first would let the
	 * read-only path decide for a user who also holds a writable one, and
	 * every later step works from the node this returns: the open, the
	 * metadata, the sync. Picking the writable one keeps all three on the
	 * same mount.
	 *
	 * This is the pattern Nextcloud Text uses for the same reason.
	 */
	public function resolveUserFileNodeById(string $uid, int $fileId): File {
		$nodes = $this->userFolder($uid)->getById($fileId);
		$prefix = '/' . $uid . '/files/';
		$fallback = null;
		foreach ($nodes as $node) {
			if (!$node instanceof File) {
				continue;
			}
			if (!str_starts_with((string)$node->getPath(), $prefix)) {
				continue;
			}
			if ($node->isUpdateable()) {
				return $node;
			}
			$fallback ??= $node;
		}
		if ($fallback !== null) {
			return $fallback;
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
		$fallback = null;
		foreach ($nodes as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			$path = rtrim((string)$node->getPath(), '/');
			// The separator stays in the prefix test so a sibling that merely
			// starts the same way — /<uid>/files_versions — is still refused.
			// The root is accepted here too. It should have been answered by
			// id above, and getById() is not expected to return the folder it
			// searches — but "not expected to" is an implementation detail,
			// and this is the path create-by-parent takes into "All files".
			if ($path !== $root && !str_starts_with($path, $root . '/')) {
				continue;
			}
			// One folder can be reached by several shares, and only some of
			// them may allow creating. Taking the first hit made the answer
			// depend on the order `getById()` happened to return, so a user
			// with both a read-only and a writable path to the same folder
			// could be refused on the strength of the wrong one. Same rule
			// the file lookup above already follows.
			if ($node->isCreatable()) {
				return $node;
			}
			$fallback ??= $node;
		}
		if ($fallback !== null) {
			return $fallback;
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
	 * Only the two the interface declares. NotPermittedException is in OCP
	 * and can be named; NoUserException lives in `OC\User\` and is not
	 * part of the public API an app may reference, so it is matched by
	 * class name. Everything else — a storage that is down, a mount that
	 * fails to set up — is left alone on purpose: "I cannot reach your
	 * files right now" is not the same answer as "that file is not there",
	 * and reporting it as the latter would hide an outage behind a tidy
	 * 404 with nothing in the log.
	 *
	 * @throws NotFoundException
	 */
	private function userFolder(string $uid): Folder {
		try {
			return $this->rootFolder->getUserFolder($uid);
		} catch (\Exception $e) {
			if (!$e instanceof NotPermittedException && !is_a($e, self::NO_USER_EXCEPTION)) {
				throw $e;
			}
			$this->logger->debug('Cannot access the user file tree', ['uid' => $uid, 'exception' => $e]);
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

		$userFolder = $this->userFolder($uid);
		try {
			$node = $userFolder->get($relativePath);
		} catch (NotPermittedException $e) {
			// Same rule as above: not being allowed to look is, to every
			// caller here, the same answer as the file not being there. A
			// sub-mount that is *down* still surfaces as itself.
			$this->logger->debug('Not permitted to read the path', ['uid' => $uid, 'exception' => $e]);
			throw new NotFoundException('Cannot access the requested path.', 0, $e);
		}
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
