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
	 * Etherpad's own rule for a pad id (isValidPadId() in its PadManager):
	 * an optional group - `g.` and sixteen letters or digits, as
	 * createGroup() makes it - with `$`, then a name of 1 to 50 characters
	 * without `$`.
	 */
	private const VALID_PATTERN = '/^(g\.[a-zA-Z0-9]{16}\$)?[^$]{1,50}$/u';

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
		return preg_match(self::VALID_PATTERN, $padId) === 1;
	}
}
