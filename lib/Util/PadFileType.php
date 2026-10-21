<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * What makes a file one of ours: the name it carries and the type it is
 * registered as.
 *
 * The suffix is matched case-insensitively because Nextcloud accepts
 * `.PAD` from a desktop client or a WebDAV upload, and the mount the file
 * arrives on decides how the name is cased.
 *
 * The two answers can disagree: a file that came in while the MIME type
 * was not mapped is a pad by name and not by type until RegisterMimeType's
 * repair types it, which it does not on every run (see there).
 */
final class PadFileType {
	/** Without the dot, which is how Nextcloud's mime mapping names it. */
	public const EXTENSION = 'pad';
	public const SUFFIX = '.' . self::EXTENSION;
	public const MIME = 'application/x-etherpad-nextcloud';

	public static function isPad(string $nameOrPath): bool {
		return str_ends_with(strtolower($nameOrPath), self::SUFFIX);
	}

	public static function withSuffix(string $nameOrPath): string {
		if ($nameOrPath === '') {
			// Would name a hidden dotfile rather than a pad.
			throw new \InvalidArgumentException('Cannot add the pad suffix to an empty name.');
		}

		return self::isPad($nameOrPath) ? $nameOrPath : $nameOrPath . self::SUFFIX;
	}

	/**
	 * Anchored so it cannot catch an unrelated type, and quoted because the
	 * slash in the mime type would otherwise end the pattern.
	 */
	public static function mimePattern(): string {
		return '/^' . preg_quote(self::MIME, '/') . '\z/';
	}
}
