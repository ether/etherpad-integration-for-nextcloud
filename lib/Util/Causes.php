<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * A failure of a given type in a throwable or among its causes. The HTTP
 * client and EtherpadClient wrap what they catch, so a caller that tells
 * failures apart by type looks through the wrapping.
 */
final class Causes {
	/**
	 * @template T of \Throwable
	 * @param class-string<T> $type
	 * @return T|null
	 */
	public static function find(\Throwable $e, string $type): ?\Throwable {
		for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof $type) {
				return $cause;
			}
		}
		return null;
	}
}
