<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\IURLGenerator;

/**
 * Builds URLs that point back into Nextcloud public shares.
 *
 * This is intentionally separate from the internal files-viewer URL builders:
 * public shares use /s/{token} routes and folder-selection query parameters.
 */
class PublicShareUrlBuilder {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private PathNormalizer $pathNormalizer,
	) {
	}

	public function buildShareBaseUrl(string $token): string {
		$webroot = rtrim($this->urlGenerator->getWebroot(), '/');
		return $webroot . '/s/' . rawurlencode($token);
	}

	public function buildShareRedirectUrl(string $token, mixed $fileParam): string {
		$base = $this->buildShareBaseUrl($token);
		if (!is_string($fileParam)) {
			throw new InvalidShareFilePathException('Invalid file path.');
		}

		$rawFile = trim($fileParam);
		if ($rawFile === '') {
			return $base . '?dir=' . rawurlencode('/');
		}

		try {
			$normalized = $this->pathNormalizer->normalizePublicShareFilePath($rawFile, $token);
		} catch (\InvalidArgumentException $e) {
			throw new InvalidShareFilePathException('Invalid file path.', 0, $e);
		}
		if ($normalized === '') {
			return $base . '?dir=' . rawurlencode('/');
		}

		$path = trim($normalized, '/');
		if ($path === '') {
			return $base . '?dir=' . rawurlencode('/');
		}

		$dir = dirname($path);
		if ($dir === '.') {
			$dir = '/';
		} else {
			$dir = '/' . $dir;
		}
		$fileName = basename($path);
		if (!PadFileType::isPad($fileName)) {
			throw new NotAPadFileException('The selected file is not a .pad document.');
		}

		return $base . '?path=' . rawurlencode($dir) . '&files=' . rawurlencode($fileName);
	}
}
