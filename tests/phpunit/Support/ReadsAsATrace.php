<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

/**
 * What Nextcloud writes of an argument when it serializes an exception's
 * trace, and what the app's classes let it see: the two views a test of
 * "nothing of it in a trace" needs.
 */
trait ReadsAsATrace {
	/**
	 * As ExceptionSerializer::encodeArg() does, without its limits on
	 * depth and length: of an object its class and its public fields, by
	 * get_object_vars() from outside the class, and those of the objects
	 * in them.
	 */
	private static function asATraceShows(mixed $argument): mixed {
		if (is_object($argument)) {
			return array_map(self::asATraceShows(...), ['__class__' => $argument::class] + get_object_vars($argument));
		}
		return is_array($argument) ? array_map(self::asATraceShows(...), $argument) : $argument;
	}

	/**
	 * The fields under lib that anything outside their class can read and
	 * whose name matches $names, each as `Class::$field`, but for the
	 * ones $excused: a field under such a name that holds nothing of the
	 * kind, each with what it holds instead.
	 *
	 * And the excuses no field needs: one for a field that is gone, or no
	 * longer public, would excuse the next of that name.
	 *
	 * @param array<string,string> $excused
	 * @return array{list<string>, list<string>}
	 */
	private static function publicFieldsNamed(string $names, array $excused): array {
		$found = [];
		$seen = [];
		foreach (AppSource::publicFields() as [$class, $field]) {
			if (preg_match($names, $field) !== 1) {
				continue;
			}
			$key = $class . '::$' . $field;
			$seen[$key] = true;
			if (!isset($excused[$key])) {
				$found[] = $key;
			}
		}
		return [$found, array_values(array_diff(array_keys($excused), array_keys($seen)))];
	}
}
