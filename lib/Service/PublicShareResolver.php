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
		if ($fileIdParam === null || $fileIdParam === '') {
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
	 * getById() can return the same file more than once - a folder reached
	 * through several mounts - and the entries differ in permissions, so
	 * the first readable one inside this share is the answer rather than
	 * simply the first.
	 */
	/**
	 * The same shape as UserNodeResolver::resolveUserFileNodeById(), and for
	 * the same reason: one file can be reachable by several paths with
	 * different permissions, and getById() order must not decide which one
	 * every later step works from. A writable match wins over a readable
	 * one, since a writable share that opened the read-only entry would
	 * silently hand out a read-only pad.
	 *
	 * @return array{File,string} the file and its path inside the share
	 */
	private function fileInShareById(Folder $shareFolder, int $fileId): array {
		$fallback = null;

		try {
			$candidates = $shareFolder->getById($fileId);
		} catch (NotFoundException) {
			// A mount inside the share that is momentarily unresolvable.
			// The path branch answers that with the same error rather than
			// letting it surface as an unhandled failure.
			throw new ShareItemUnavailableException('This shared item is no longer available.');
		}

		foreach ($candidates as $candidate) {
			if (!$candidate instanceof File) {
				continue;
			}
			if ((((int)$candidate->getPermissions()) & Constants::PERMISSION_READ) === 0) {
				continue;
			}
			// getById() is scoped to this folder, so null should not happen -
			// and if it ever did, an id from elsewhere in the owner's storage
			// would stop here rather than fall back to the path.
			$relativePath = $shareFolder->getRelativePath($candidate->getPath());
			if ($relativePath === null) {
				continue;
			}

			$match = [$candidate, ltrim($relativePath, '/')];
			if ($candidate->isUpdateable()) {
				return $match;
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
			// Only when the caller sent one. A single-file share has nothing
			// to select, so `file` was never read there - normalising it
			// regardless would refuse links that have always worked.
			if (is_string($fileParam) && $fileParam !== '') {
				$requestedPath = $this->requestedPath($fileParam, $token);
			}
			if ($node instanceof Folder) {
				// The whole path inside the share, not the name: `A.pad` and
				// `Sub/A.pad` are two files, and comparing names would call
				// them the same one.
				[$node, $named] = $this->fileInShareById($node, $requestedId);
			} elseif ($node instanceof File) {
				if ((int)$node->getId() !== $requestedId) {
					throw new ShareFileNotInShareException('The selected file is not part of this share.');
				}
				// A single-file share has no path inside it; what a caller
				// can name is the file itself.
				$named = $node->getName();
			} else {
				throw new ShareFileNotInShareException('The selected item is not a file.');
			}

			// Both given and naming different files: neither is opened. A
			// preference either way is the shape this exists to remove -
			// "the id did not work, so something else was opened".
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
			(((int)$share->getPermissions()) & Constants::PERMISSION_UPDATE) === 0,
			$node->getName(),
		);
	}
}
