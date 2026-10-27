<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * What an Etherpad pad id says about itself, two ways. Loosely, which group
 * an id names: the one rule every reader of a binding shares, so all of
 * them see the same groups. Strictly, whether Etherpad's API takes an id
 * that comes from outside (isValid()).
 *
 * Neither is a permission check: knowing that an id *names* a group says
 * nothing about whose group it is.
 */
final class PadId {
	/** `g.<group>$<pad name>`, the shape Etherpad gives a pad inside a group. */
	private const GROUP_PAD_PATTERN = '/^(g\.[^$]+)\$.+$/';

	/**
	 * Etherpad's own rule for a pad id as it has it from 3.3.3 (isValidPadId()
	 * in its PadManager, read in 3.3.11), but for the name's length. A `:` is
	 * how Etherpad's database keys a pad's records, and Etherpad before 3.3.3
	 * still took it; no editor ever opened a pad under `.` or `..`, as a
	 * browser makes `/p/.` another path. The dot after `g` is a dot here,
	 * where Etherpad's lets it be any character but a line break: no group
	 * of Etherpad's is named otherwise.
	 *
	 * @see https://github.com/ether/etherpad/blob/v3.3.11/src/node/db/PadManager.ts
	 */
	private const VALID_PATTERN = '/^(?!\.{1,2}$)(?:g\.[a-zA-Z0-9]{16}\$)?([^$:]+)$/uD';

	/** The longest name Etherpad takes, in UTF-16 code units, as JavaScript counts a string. */
	public const MAX_NAME_UNITS = 50;

	/** The group a pad belongs to, or null if the id does not name one. */
	public static function groupIdOf(string $padId): ?string {
		return preg_match(self::GROUP_PAD_PATTERN, $padId, $matches) === 1 ? $matches[1] : null;
	}

	public static function isGroupPad(string $padId): bool {
		return self::groupIdOf($padId) !== null;
	}

	/**
	 * Whether Etherpad's API takes this id. It refuses a call naming one that
	 * fails (getPadSafe()), so a pad under it could be neither read, synced
	 * nor deleted - even one an Etherpad before 3.3.3 made, which its editor
	 * still opens. Syntax only, not whether the pad exists. For an id that
	 * comes from outside, before it is bound.
	 */
	public static function isValid(string $padId): bool {
		if (preg_match(self::VALID_PATTERN, $padId, $matches) !== 1) {
			return false;
		}
		// Counted as Etherpad counts it: a character past the Basic
		// Multilingual Plane is two units.
		return strlen((string)mb_convert_encoding($matches[1], 'UTF-16LE', 'UTF-8')) <= 2 * self::MAX_NAME_UNITS;
	}
}
