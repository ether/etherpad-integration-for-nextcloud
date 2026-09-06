<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use OCP\Files\NotFoundException;

class PadInitializationService {
	public const STATUS_ALREADY_INITIALIZED = 'already_initialized';
	public const STATUS_INITIALIZED = 'initialized';
	public const STATUS_MIGRATED_FROM_LEGACY = 'migrated_from_legacy';

	public function __construct(
		private PadFileService $padFileService,
		private PathNormalizer $padPaths,
		private UserNodeResolver $userNodeResolver,
		private PadBootstrapService $padBootstrapService,
	) {
	}

	/**
	 * @throws NotFoundException
	 */
	public function initializeByPath(string $uid, string $file): PadInitializationResult {
		$path = $this->padPaths->normalizeViewerFilePath($file);
		if ($path === '') {
			throw new \InvalidArgumentException('Invalid file path.');
		}

		$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $path);
		return $this->initializeNode($uid, $node);
	}

	/**
	 * @throws NotFoundException
	 */
	public function initializeById(string $uid, int $fileId): PadInitializationResult {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		return $this->initializeNode($uid, $node);
	}

	private function initializeNode(string $uid, File $file): PadInitializationResult {
		// The id first: a file this app cannot address is refused without a
		// read, which has failure modes of its own — a lock, a storage
		// error — that would otherwise be reported in its place.
		$fileId = (int)$file->getId();
		if ($fileId <= 0) {
			throw new \RuntimeException('Could not resolve file ID.');
		}

		$content = (string)$file->getContent();
		$path = $this->userNodeResolver->toUserAbsolutePath($uid, $file);
		try {
			$pad = $this->padFileService->readPad($content);
			return new PadInitializationResult(
				status: self::STATUS_ALREADY_INITIALIZED,
				file: $path,
				fileId: $fileId,
				padId: $pad->padId,
				accessMode: $pad->accessMode,
			);
		} catch (MissingFrontmatterException) {
			// Only the narrower one: a legacy or empty .pad continues into
			// the bootstrap below, while any other format error stays
			// unhandled and reaches the caller.
		}

		$wasLegacyMigration = $this->padBootstrapService->initializeMissingFrontmatter($uid, $file, $content);
		$pad = $this->padFileService->readPad((string)$file->getContent());

		return new PadInitializationResult(
			status: $wasLegacyMigration ? self::STATUS_MIGRATED_FROM_LEGACY : self::STATUS_INITIALIZED,
			file: $path,
			fileId: $fileId,
			padId: $pad->padId,
			accessMode: $pad->accessMode,
		);
	}
}
