<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Exception\NoShareFileSelectedException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\ShareFileNotInShareException;
use OCA\EtherpadNextcloud\Exception\ShareItemUnavailableException;
use OCA\EtherpadNextcloud\Exception\ShareReadForbiddenException;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * Resolves a public share token and optional folder-file parameter to a .pad file.
 *
 * Permission, existence and file-type failures are expressed as typed public
 * share exceptions so controller-facing code can map them consistently.
 */
class PublicShareResolver {
	public function __construct(
		private IManager $shareManager,
		private PathNormalizer $pathNormalizer,
	) {
	}

	public function resolveShare(string $token, ?IShare $cached = null): IShare {
		if ($cached instanceof IShare) {
			return $cached;
		}

		try {
			return $this->shareManager->getShareByToken($token);
		} catch (ShareNotFound) {
			throw new InvalidShareTokenException('This share link is invalid or has expired.');
		}
	}

	private function requestedFileId(mixed $fileIdParam): ?int {
		// Absent only. `fileId=` was sent, and a sent id that cannot be
		// used is refused rather than replaced by the path.
		if ($fileIdParam === null) {
			return null;
		}
		if (!is_int($fileIdParam) && !(is_string($fileIdParam) && ctype_digit($fileIdParam))) {
			throw new InvalidShareFilePathException('Invalid file id.');
		}

		$fileId = (int)$fileIdParam;
		if ($fileId <= 0) {
			throw new InvalidShareFilePathException('Invalid file id.');
		}

		return $fileId;
	}

	private function requestedPath(mixed $fileParam, string $token): string {
		try {
			return $this->pathNormalizer->normalizePublicShareFilePath($fileParam, $token);
		} catch (\InvalidArgumentException $e) {
			throw new InvalidShareFilePathException('Invalid file path.', 0, $e);
		}
	}

	/**
	 * One file can be reachable by several paths with different permissions,
	 * so getById() order must not decide which one is taken.
	 * UserNodeResolver::resolveUserFileNodeById() answers this for a
	 * signed-in user.
	 *
	 * @return array{File,string} the file and its path inside the share
	 */
	private function fileInShareById(Folder $shareFolder, int $fileId, string $requestedPath): array {
		$fallback = null;

		foreach ($shareFolder->getById($fileId) as $candidate) {
			if (!$candidate instanceof File) {
				continue;
			}

			// Every question asked of a candidate can fail on a mount that
			// has gone away since getById() listed it, and one that cannot
			// answer takes only itself out of the running.
			try {
				if ((((int)$candidate->getPermissions()) & Constants::PERMISSION_READ) === 0) {
					continue;
				}
				// Scoped to this folder by contract, so null should not
				// happen; if it ever did, the id stops here rather than
				// falling back.
				$relativePath = $shareFolder->getRelativePath($candidate->getPath());
				if ($relativePath === null) {
					continue;
				}

				$match = [$candidate, ltrim($relativePath, '/')];
				// A named path picks its mount; the preference below only
				// settles an id-only request.
				if ($requestedPath !== '' && $match[1] === $requestedPath) {
					return $match;
				}
				if ($requestedPath === '' && $candidate->isUpdateable()) {
					return $match;
				}
			} catch (NotFoundException | InvalidPathException) {
				continue;
			}

			$fallback ??= $match;
		}

		if ($fallback !== null) {
			return $fallback;
		}

		throw new ShareFileNotInShareException('The selected file is not part of this share.');
	}

	public function resolvePadFile(
		IShare $share,
		mixed $fileParam,
		string $token,
		mixed $fileIdParam = null,
	): ResolvedPadShare {
		if ((((int)$share->getPermissions()) & Constants::PERMISSION_READ) === 0) {
			throw new ShareReadForbiddenException('This share link does not allow reading files.');
		}

		try {
			$node = $share->getNode();
		} catch (NotFoundException) {
			throw new ShareItemUnavailableException('This shared item is no longer available.');
		}

		$requestedId = $this->requestedFileId($fileIdParam);
		$requestedPath = '';

		if ($requestedId !== null) {
			// Only when sent: a single-file share has nothing to select.
			if (is_string($fileParam) && $fileParam !== '') {
				$requestedPath = $this->requestedPath($fileParam, $token);
			}
			if ($node instanceof Folder) {
				// The whole path inside the share, not the name: `A.pad` and
				// `Sub/A.pad` are two files, and comparing names would call
				// them the same one.
				[$node, $named] = $this->fileInShareById($node, $requestedId, $requestedPath);
			} elseif ($node instanceof File) {
				if ((int)$node->getId() !== $requestedId) {
					throw new ShareFileNotInShareException('The selected file is not part of this share.');
				}
				$named = $node->getName();
			} else {
				throw new ShareFileNotInShareException('The selected item is not a file.');
			}

			// Neither is opened: preferring one would be "the id did not
			// work, so something else was opened".
			if ($requestedPath !== '' && $requestedPath !== $named) {
				throw new InvalidShareFilePathException('The file id and the file path name different files.');
			}
		} elseif ($node instanceof Folder) {
			$requestedPath = $this->requestedPath($fileParam, $token);
			if ($requestedPath === '') {
				throw new NoShareFileSelectedException('No .pad file selected. Open a .pad file from this shared folder.');
			}
			try {
				$node = $node->get($requestedPath);
			} catch (NotFoundException) {
				throw new ShareFileNotInShareException('The selected file does not exist in this share.');
			}
		}

		if (!$node instanceof File) {
			throw new ShareFileNotInShareException('The selected item is not a file.');
		}
		if (!PadFileType::isPad($node->getName())) {
			throw new NotAPadFileException('The selected file is not a .pad document.');
		}

		return new ResolvedPadShare(
			$node,
			(int)$node->getId(),
			// Both levels: the share can allow writing where this mount
			// does not.
			(((int)$share->getPermissions()) & Constants::PERMISSION_UPDATE) === 0
				|| !$node->isUpdateable(),
			$node->getName(),
		);
	}
}
