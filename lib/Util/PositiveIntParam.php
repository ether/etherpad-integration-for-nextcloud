<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * A positive whole number as a request sent it - a file id, a count - read
 * strictly: an int or a string of digits. Not the typed argument Nextcloud
 * casts a controller's parameter to, which reads `7x` as 7 and an empty
 * value as 0: a file the request did not name.
 */
final class PositiveIntParam {
	/**
	 * The number, or null when the request sent none (the parameter absent).
	 *
	 * @throws \InvalidArgumentException when one was sent that is no positive whole number
	 */
	public static function read(mixed $raw): ?int {
		if ($raw === null) {
			return null;
		}
		$fileId = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int)$raw : 0;
		if ($fileId <= 0) {
			throw new \InvalidArgumentException('Not a positive whole number.');
		}
		return $fileId;
	}
}
