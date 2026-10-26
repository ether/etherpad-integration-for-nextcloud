<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * What an Etherpad pad id says about itself.
 *
 * There were three rules for one concept: the session code required exactly
 * sixteen characters after `g.`, the access-mode inference accepted any
 * prefix, and the delete path had a copy of the strict one. A pad bound as
 * protected by the loose rule was then not recognised as a group pad by the
 * strict one, and its group was left behind — silently, since the two
 * failures look nothing alike.
 *
 * The loose rule is the one that matches how a binding is classified, so it
 * is the one that survives. Nothing here is a permission check: knowing
 * that an id *names* a group says nothing about whose group it is.
 */
final class PadId {
	/** `g.<group>$<pad name>`, the shape Etherpad gives a pad inside a group. */
	private const GROUP_PAD_PATTERN = '/^(g\.[^$]+)\$.+$/';

	/**
	 * Etherpad's own rule for a pad id, as Etherpad 3.3 has it (isValidPadId()
	 * in its PadManager, read in 3.3.11): an optional group - `g.` and
	 * sixteen letters or digits, as createGroup() makes it - with `$`, then
	 * a name without `$` or `:`; not `.` or `..`. Etherpad 2 still took `:`
	 * and the two dot names; an upgrade would leave such a pad unreachable.
	 * The dot after `g` is a dot here, where Etherpad's pattern lets it be
	 * any character: no group of Etherpad's is named otherwise. The name's
	 * length is isValid()'s to check.
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
	 * Whether Etherpad could hold a pad under this id: one that fails names
	 * no pad, whatever wrote it. For an id that comes from outside - a file,
	 * an import - before it is bound; the loose rule above still decides
	 * what an id already bound names.
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
