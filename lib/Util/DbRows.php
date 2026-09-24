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
 *
 * The column readers read a column as what it holds - an integer as an int
 * or the string of digits a driver may give - and refuse what it cannot hold:
 * a column the query did not select, null in a NOT NULL column, or a value
 * of another kind.
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
		// Through one() rather than a loop over $rows: on OCP 31 each element
		// is mixed, and a foreach would assign it as mixed, an issue of its own.
		$typed = [];
		foreach (array_map(self::one(...), $rows) as $row) {
			if ($row !== null) {
				$typed[] = $row;
			}
		}
		return $typed;
	}

	/**
	 * A NOT NULL integer column. Drivers hand integers back as ints or as
	 * strings of digits, so both are read.
	 *
	 * @param array<string,mixed> $row
	 * @throws \UnexpectedValueException when the row lacks the column, or holds null or something else
	 */
	public static function int(array $row, string $column): int {
		return self::nullableInt($row, $column) ?? throw self::missing($column);
	}

	/**
	 * A nullable integer column.
	 *
	 * @param array<string,mixed> $row
	 * @throws \UnexpectedValueException when the row lacks the column, or holds something else
	 */
	public static function nullableInt(array $row, string $column): ?int {
		return self::asNullableInt(self::column($row, $column), $column);
	}

	/**
	 * A NOT NULL string column.
	 *
	 * @param array<string,mixed> $row
	 * @throws \UnexpectedValueException when the row lacks the column, or holds null or something else
	 */
	public static function string(array $row, string $column): string {
		return self::nullableString($row, $column) ?? throw self::missing($column);
	}

	/**
	 * A nullable string column.
	 *
	 * @param array<string,mixed> $row
	 * @throws \UnexpectedValueException when the row lacks the column, or holds something else
	 */
	public static function nullableString(array $row, string $column): ?string {
		return self::asNullableString(self::column($row, $column), $column);
	}

	/**
	 * A column the query selected. Missing, it was not selected, and a value
	 * made up in its place would be read as data.
	 *
	 * @param array<string,mixed> $row
	 * @throws \UnexpectedValueException
	 */
	private static function column(array $row, string $column): mixed {
		if (!array_key_exists($column, $row)) {
			throw new \UnexpectedValueException("The row has no column $column: the query did not select it.");
		}
		return $row[$column];
	}

	/**
	 * Taken as a parameter rather than a local, as one() does it: a mixed
	 * local would be an issue of its own.
	 */
	private static function asNullableInt(mixed $value, string $column): ?int {
		if ($value === null || is_int($value)) {
			return $value;
		}
		// What a driver gives for an integer column: digits, a minus at most.
		// is_numeric() would also pass a decimal, an exponent or padding, and
		// (int) would turn each into another integer; filter_var() refuses
		// one out of range, and leading zeros.
		$int = is_string($value) && preg_match('/\A-?[0-9]+\z/', $value) === 1 ? filter_var($value, FILTER_VALIDATE_INT) : false;
		return $int !== false ? $int : throw new \UnexpectedValueException("Column $column holds no integer.");
	}

	private static function asNullableString(mixed $value, string $column): ?string {
		return $value === null || is_string($value) ? $value : throw new \UnexpectedValueException("Column $column holds no string.");
	}

	private static function missing(string $column): \UnexpectedValueException {
		return new \UnexpectedValueException("Column $column is NOT NULL, and the row holds no value for it.");
	}
}
