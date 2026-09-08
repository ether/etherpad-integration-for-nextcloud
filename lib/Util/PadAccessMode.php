<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * The kinds of pad this app makes.
 *
 * A pad is either its own pad, reachable by anyone holding its id, or a pad
 * inside an Etherpad group that a session grants access to. Everything that
 * has to tell them apart asks here, so a third kind is added in one place
 * rather than found in five.
 */
enum PadAccessMode: string {
	case Public = 'public';
	case Protected = 'protected';

	/**
	 * The mode a stored value names, or null when it names none.
	 *
	 * Callers keep their own refusal: a controller answers 400, a
	 * frontmatter parser calls the file malformed, and a provisioning
	 * request is a programming error. What they no longer each decide is
	 * which values exist.
	 */
	public static function tryFromValue(mixed $value): ?self {
		return is_string($value) ? self::tryFrom($value) : null;
	}
}
