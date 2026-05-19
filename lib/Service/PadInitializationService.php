<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCP\Files\File;
use OCP\Files\NotFoundException;

class PadInitializationService {
	public const STATUS_ALREADY_INITIALIZED = 'already_initialized';
	public const STATUS_INITIALIZED = 'initialized';
	public const STATUS_MIGRATED_FROM_LEGACY = 'migrated_from_legacy';

	public function __construct(
		private PadFileService $padFileService,
		private PadPathService $padPaths,
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

	private function initializeNode(string $uid, File $node): PadInitializationResult {
		$fileId = (int)$node->getId();
		if ($fileId <= 0) {
			throw new \RuntimeException('Could not resolve file ID.');
		}

		return $this->initialize($uid, $node, (string)$node->getContent());
	}

	public function initialize(string $uid, File $file, string $content): PadInitializationResult {
		$fileId = (int)$file->getId();
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
			// Explicitly continue with bootstrap flow for legacy or empty .pad files.
		} catch (PadFileFormatException $e) {
			throw $e;
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
