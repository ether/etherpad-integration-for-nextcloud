<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * What an Etherpad pad id says about itself, two ways. Loosely, which group
 * an id names: one rule for every reader of a binding, as two rules once
 * left a group behind that one of them did not see as a group. Strictly,
 * whether Etherpad's API takes an id that comes from outside (isValid()).
 *
 * Neither is a permission check: knowing that an id *names* a group says
 * nothing about whose group it is.
 */
final class PadId {
	/** `g.<group>$<pad name>`, the shape Etherpad gives a pad inside a group. */
	private const GROUP_PAD_PATTERN = '/^(g\.[^$]+)\$.+$/';

	/**
	 * Etherpad's own rule for a pad id, as Etherpad 3.3 has it (isValidPadId()
	 * in its PadManager): an optional group - `g.` and sixteen letters or
	 * digits, as createGroup() makes it - with `$`, then a name without `$`
	 * or `:`; not `.` or `..`. A `:` is how Etherpad's database keys a pad's
	 * records, and Etherpad 2 still took it; a browser makes `/p/.` and
	 * `/p/..` another path, so no pad under those was ever reachable. The
	 * dot after `g` is a dot here, where Etherpad's pattern lets it be any
	 * character: no group of Etherpad's is named otherwise. The name's
	 * length is isValid()'s to check.
	 *
	 * @see https://github.com/ether/etherpad-lite/blob/v3.3.11/src/node/db/PadManager.ts
	 */
	private const VALID_PATTERN = '/^(?!\.{1,2}$)(?:g\.[a-zA-Z0-9]{16}\$)?([^$:]{1,50})$/uD';

	/** The longest name Etherpad takes, in UTF-16 code units, as JavaScript counts a string. */
	private const MAX_NAME_UNITS = 50;

	/** The group a pad belongs to, or null if the id does not name one. */
	public static function groupIdOf(string $padId): ?string {
		return preg_match(self::GROUP_PAD_PATTERN, $padId, $matches) === 1 ? $matches[1] : null;
	}

	public static function isGroupPad(string $padId): bool {
		return self::groupIdOf($padId) !== null;
	}

	/**
	 * Whether Etherpad's API takes this id: every call naming one that fails
	 * is refused (getPadSafe() in its API), so a pad under it could be
	 * neither read, synced nor deleted - even one an older Etherpad made,
	 * which its editor still opens. Syntax only; whether the pad exists is
	 * another question. For an id that comes from outside - a file, an
	 * import - before it is bound; what an id already bound names stays the
	 * loose rule's.
	 */
	public static function isValid(string $padId): bool {
		if (preg_match(self::VALID_PATTERN, $padId, $matches) !== 1) {
			return false;
		}
		// The pattern counts code points; a character past the Basic
		// Multilingual Plane is two units to Etherpad.
		$utf16 = mb_convert_encoding($matches[1], 'UTF-16LE', 'UTF-8');
		return $utf16 !== false && strlen($utf16) <= 2 * self::MAX_NAME_UNITS;
	}
}
