<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

use OCP\IURLGenerator;

/**
 * The address the Files app opens one file at.
 *
 * A stateless formatter: it holds nothing between calls, so the URL
 * generator is a parameter rather than a constructor dependency. Both
 * callers keep one of their own for their other routes anyway. Should this
 * grow configuration, or a second Files route to choose between, it wants
 * to become a service like PublicShareUrlBuilder.
 */
final class FilesViewerUrl {
	public static function forFile(IURLGenerator $urlGenerator, int $fileId, string $absolutePath): string {
		$dir = dirname($absolutePath);
		// `files.view.index` resolves to '/apps/files'; the canonical URL
		// the Files app routes to a specific file is
		// `/apps/files/{view}/{fileid}` with `files` as the default view.
		$base = rtrim($urlGenerator->linkToRoute('files.view.index'), '/');
		return $base . '/files/' . rawurlencode((string)$fileId)
			. '?dir=' . rawurlencode($dir)
			. '&editing=false&openfile=true';
	}
}
