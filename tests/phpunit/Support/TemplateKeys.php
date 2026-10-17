<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

/** The snake_case keys a template under templates/ reads from its parameters. */
final class TemplateKeys {
	/**
	 * @return array{top: list<string>, l10n: list<string>}
	 *   the keys read straight off `$_`, and those under `$_['l10n']`, each
	 *   once, sorted
	 */
	public static function read(string $template): array {
		$source = (string)file_get_contents(__DIR__ . '/../../../templates/' . $template . '.php');
		preg_match_all("/\\\$_\\['([a-z0-9_]+)'\\](?!\\[)/", $source, $top);
		preg_match_all("/\\\$_\\['l10n'\\]\\['([a-z0-9_]+)'\\]/", $source, $l10n);
		return ['top' => self::once($top[1]), 'l10n' => self::once($l10n[1])];
	}

	/**
	 * @param list<string> $keys
	 * @return list<string>
	 */
	private static function once(array $keys): array {
		$keys = array_values(array_unique($keys));
		sort($keys);
		return $keys;
	}
}
