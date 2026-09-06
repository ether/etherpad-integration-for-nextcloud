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

	public function resolvePadFile(IShare $share, mixed $fileParam, string $token): ResolvedPadShare {
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

		if ($node instanceof Folder) {
			try {
				$normalized = $this->pathNormalizer->normalizePublicShareFilePath($fileParam, $token);
			} catch (\InvalidArgumentException $e) {
				throw new InvalidShareFilePathException('Invalid file path.', 0, $e);
			}
			if ($normalized === '') {
				throw new NoShareFileSelectedException('No .pad file selected. Open a .pad file from this shared folder.');
			}
			$selectedRelativePath = $normalized;
			try {
				$node = $node->get($normalized);
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
