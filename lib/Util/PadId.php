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
 * them see the same groups. Strictly, whether Etherpad's API acts on an id
 * as it is (isValid()).
 *
 * Neither is a permission check: knowing that an id *names* a group says
 * nothing about whose group it is.
 */
final class PadId {
	/** `g.<group>$<pad name>`, the shape Etherpad gives a pad inside a group. */
	private const GROUP_PAD_PATTERN = '/^(g\.[^$]+)\$.+$/';

	/**
	 * Etherpad's own rule for a pad id as it has it from 3.3.3 (isValidPadId()
	 * in its PadManager, read in 3.3.11), refusing whitespace besides, as
	 * JavaScript's `\s` counts it; the name's length is isValid()'s to count.
	 * A `:` is how Etherpad's database keys a pad's records; an Etherpad
	 * before 3.3.3 still took it, and is held to the rule all the same, as
	 * after an upgrade the app could not reach such a pad. Whitespace passes
	 * Etherpad's rule, but its sanitizePadId() rewrites it as it rewrites
	 * `:` (isValid()). No editor ever opened a pad under `.` or `..`, as a
	 * browser makes `/p/.` another path. The dot after `g` is a dot here,
	 * where Etherpad's lets it be any character but a line break: no group
	 * of Etherpad's is named otherwise.
	 *
	 * @see https://github.com/ether/etherpad/blob/v3.3.11/src/node/db/PadManager.ts
	 * @see https://tc39.es/ecma262/multipage/text-processing.html#sec-runtime-semantics-characterclassescape what JavaScript's `\s` takes
	 */
	private const VALID_PATTERN = '/^(?!\.{1,2}$)(?:g\.[a-zA-Z0-9]{16}\$)?([^$:\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+)$/uD';

	/**
	 * The public ids this app gives its pads, the same since 1.0.0
	 * (PadBootstrapService::newPadId(), RestoreService::buildPublicRestorePadId()).
	 */
	private const APP_PUBLIC_PATTERN = '/^(?:nc-[a-z0-9]{24}|r-.*-[a-z0-9]{12})$/D';

	/** The longest name Etherpad takes, in UTF-16 code units, as JavaScript counts a string. */
	public const MAX_NAME_UNITS = 50;

	/** The group a pad belongs to, or null if the id does not name one. */
	public static function groupIdOf(string $padId): ?string {
		return preg_match(self::GROUP_PAD_PATTERN, $padId, $matches) === 1 ? $matches[1] : null;
	}

	public static function isGroupPad(string $padId): bool {
		return self::groupIdOf($padId) !== null;
	}

	/** Whether this has the form of a public id this app gives; which installation made the pad it cannot say. */
	public static function isAppPublicId(string $padId): bool {
		return preg_match(self::APP_PUBLIC_PATTERN, $padId) === 1;
	}

	/** Whether this is a public id not of the app's form, whose pad stays when its file goes for good (GoneFileSweep). */
	public static function isOtherPublicId(string $padId): bool {
		return !self::isGroupPad($padId) && !self::isAppPublicId($padId);
	}

	/**
	 * Whether Etherpad's API acts on this id as it is, capitals aside: with
	 * its lowerCasePadIds on, it lowers an id no pad exists under, too.
	 * Before every call it rewrites such an id, runs of whitespace and of
	 * `:` becoming `_` (sanitizePadId() in its APIHandler), and acts on that
	 * other pad; an id that then fails its rule, it refuses (getPadSafe()) -
	 * so a pad an Etherpad before 3.3.3 made with a `:`, which its editor
	 * still opens, could be neither read, synced nor deleted. Syntax only,
	 * not whether the pad exists. For an id that comes from outside, before
	 * it is bound, and a bound one, before its pad is deleted.
	 *
	 * @see https://github.com/ether/etherpad/blob/v3.3.11/src/node/handler/APIHandler.ts
	 */
	public static function isValid(string $padId): bool {
		if (preg_match(self::VALID_PATTERN, $padId, $matches) !== 1) {
			return false;
		}
		// Counted as Etherpad counts it: a character past the Basic
		// Multilingual Plane is two units. A string: mb_convert_encoding()
		// gives false only where it has to guess the source encoding.
		/** @var string $utf16 */
		$utf16 = mb_convert_encoding($matches[1], 'UTF-16LE', 'UTF-8');
		return strlen($utf16) <= 2 * self::MAX_NAME_UNITS;
	}
}
