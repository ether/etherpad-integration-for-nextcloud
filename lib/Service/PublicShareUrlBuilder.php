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

	/**
	 * Through Nextcloud's own route, so the link carries index.php where
	 * the front controller is off.
	 */
	public function buildShareBaseUrl(string $token): string {
		return $this->urlGenerator->linkToRoute('files_sharing.sharecontroller.showShare', ['token' => $token]);
	}

	/**
	 * Checks the file before it builds the link, so a refused file costs no
	 * route lookup.
	 *
	 * @throws InvalidShareFilePathException
	 * @throws NotAPadFileException
	 */
	public function buildShareRedirectUrl(string $token, mixed $fileParam): string {
		$selection = $this->fileSelection($token, $fileParam);
		return $this->buildShareBaseUrl($token) . $selection;
	}

	/**
	 * @throws InvalidShareFilePathException
	 * @throws NotAPadFileException
	 */
	private function fileSelection(string $token, mixed $fileParam): string {
		if (!is_string($fileParam)) {
			throw new InvalidShareFilePathException('Invalid file path.');
		}

		$rawFile = trim($fileParam);
		if ($rawFile === '') {
			return '?dir=' . rawurlencode('/');
		}

		try {
			$normalized = $this->pathNormalizer->normalizePublicShareFilePath($rawFile, $token);
		} catch (\InvalidArgumentException $e) {
			throw new InvalidShareFilePathException('Invalid file path.', 0, $e);
		}
		if ($normalized === '') {
			return '?dir=' . rawurlencode('/');
		}

		$dir = dirname($normalized);
		if ($dir === '.') {
			$dir = '/';
		} else {
			$dir = '/' . $dir;
		}
		$fileName = basename($normalized);
		if (!PadFileType::isPad($fileName)) {
			throw new NotAPadFileException('The selected file is not a .pad document.');
		}

		return '?path=' . rawurlencode($dir) . '&files=' . rawurlencode($fileName);
	}
}
