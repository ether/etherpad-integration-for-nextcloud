<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * Fetched rows, typed the same on every Nextcloud the app supports.
 *
 * OCP 31 declares IResult::fetch() as mixed and fetchAll() as mixed[]; OCP
 * 34 declares both as arrays keyed by column. Code that trusts either sees a
 * different type on the other, and static analysis with it: one Psalm
 * baseline could not serve both ends of the range. Taking what was fetched
 * as mixed and checking it gives the same answer on both.
 *
 * It takes the fetched value, not the result: the result's type is the part
 * that differs, and the tests' fake results need not implement it.
 */
final class DbRows {
	/**
	 * A row from fetch(), or null for the false that ends the result.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function one(mixed $row): ?array {
		if (!is_array($row)) {
			return null;
		}
		/** @var array<string,mixed> $row fetched with FETCH_ASSOC, so keyed by column name */
		return $row;
	}

	/**
	 * The rows from fetchAll().
	 *
	 * @param array<array-key,mixed> $rows
	 * @return list<array<string,mixed>>
	 */
	public static function all(array $rows): array {
		$typed = [];
		foreach (array_map(self::one(...), $rows) as $row) {
			if ($row !== null) {
				$typed[] = $row;
			}
		}
		return $typed;
	}
}
