<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Preview;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Preview\IProviderV2;

/**
 * Minimal preview provider for `.pad` files.
 *
 * Without a provider registered, Nextcloud's `/core/preview` endpoint
 * returns 4xx for unsupported mime types — which surfaces as a network
 * error in browser dev tools every time a user opens the Files app on a
 * folder containing `.pad` files or opens the template picker.
 *
 * This provider returns a single static PNG (the same pad-icon glyph
 * that ships as the file-type SVG). Nextcloud handles downscaling to
 * the requested `$maxX` / `$maxY`, so we don't need per-size assets.
 *
 * A future iteration could render the actual snapshot text into the
 * preview (similar to how the Text app previews Markdown), but that's
 * a separate feature — this exists to silence the 4xx noise.
 */
class PadPreviewProvider implements IProviderV2 {
	private const ASSET_PATH = __DIR__ . '/../../img/preview-fallback.png';

	public function getMimeType(): string {
		// Regex matched against the file's mime type. Anchored so it
		// doesn't accidentally catch unrelated types.
		return '/^application\/x-etherpad-nextcloud$/';
	}

	public function isAvailable(FileInfo $file): bool {
		// The preview is a static asset — always available as long as
		// the bundled PNG exists on disk.
		return is_readable(self::ASSET_PATH);
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		if (!is_readable(self::ASSET_PATH)) {
			return null;
		}
		$image = new \OCP\Image();
		if (!$image->loadFromFile(self::ASSET_PATH)) {
			return null;
		}
		// NC's preview controller takes care of further downscaling /
		// caching, but resizing here avoids returning a 512×512 PNG for
		// a 32×32 request and keeps memory footprint small for grids.
		$cap = max(16, min($maxX, $maxY));
		if ($image->width() > $cap || $image->height() > $cap) {
			$image->resize($cap);
		}
		return $image;
	}
}
