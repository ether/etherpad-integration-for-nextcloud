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
		if (!is_int($fileIdParam) && !(is_string($fileIdParam) && preg_match('/^[0-9]+$/', $fileIdParam) === 1)) {
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

	private function sharedFileById(File $shared, int $fileId): File {
		if ((int)$shared->getId() !== $fileId) {
			throw new ShareFileNotInShareException('The selected file is not part of this share.');
		}

		return $shared;
	}

	/**
	 * getById() can return the same file more than once - a folder reached
	 * through several mounts - and the entries differ in permissions, so
	 * the first readable one inside this share is the answer rather than
	 * simply the first.
	 */
	private function fileInShareById(Folder $shareFolder, int $fileId): File {
		foreach ($shareFolder->getById($fileId) as $candidate) {
			if (!$candidate instanceof File) {
				continue;
			}
			if ((((int)$candidate->getPermissions()) & Constants::PERMISSION_READ) === 0) {
				continue;
			}
			// null when the path is not below the share: an id from
			// elsewhere in the owner's storage stops here, and there is no
			// path fallback behind it.
			if ($shareFolder->getRelativePath($candidate->getPath()) === null) {
				continue;
			}

			return $candidate;
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

		$isFolderShare = $node instanceof Folder;
		$selectedRelativePath = '';
		$requestedId = $this->requestedFileId($fileIdParam);
		$requestedPath = $this->requestedPath($fileParam, $token);

		if ($requestedId !== null) {
			if ($node instanceof Folder) {
				$shareFolder = $node;
				$node = $this->fileInShareById($shareFolder, $requestedId);
				$selectedRelativePath = ltrim((string)$shareFolder->getRelativePath($node->getPath()), '/');
				// The whole path inside the share, not the name: `A.pad` and
				// `Sub/A.pad` are two files, and comparing names would call
				// them the same one.
				$named = $selectedRelativePath;
			} elseif ($node instanceof File) {
				$node = $this->sharedFileById($node, $requestedId);
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
			if ($requestedPath === '') {
				throw new NoShareFileSelectedException('No .pad file selected. Open a .pad file from this shared folder.');
			}
			$selectedRelativePath = $requestedPath;
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
			$isFolderShare,
			$selectedRelativePath,
			(((int)$share->getPermissions()) & Constants::PERMISSION_UPDATE) === 0,
			$node->getName(),
		);
	}
}
