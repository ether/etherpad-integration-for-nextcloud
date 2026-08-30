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

	/** The group a pad belongs to, or null if the id does not name one. */
	public static function groupIdOf(string $padId): ?string {
		return preg_match(self::GROUP_PAD_PATTERN, $padId, $matches) === 1 ? $matches[1] : null;
	}

	public static function isGroupPad(string $padId): bool {
		return self::groupIdOf($padId) !== null;
	}
}
