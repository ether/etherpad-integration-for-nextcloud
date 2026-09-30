<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

/**
 * What Nextcloud writes of an argument when it serializes an exception's
 * trace, and what the source says of a class's public fields: the two
 * views a test of "nothing of it in a trace" needs.
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
	 * A public field under one of $names, whatever its type and with
	 * none: `public`, what may stand between it and a field's name - the
	 * other modifiers, a type - and the name. A promoted parameter is one;
	 * a parameter of a public method is not, nor the word in a comment or
	 * a string that a name happens to follow.
	 *
	 * @param string $names the names as a regular expression's alternatives
	 */
	private static function aPublicField(string $names): string {
		return '/\bpublic\s+(?:(?:readonly|static)\s+)*(?:[?\w\\\\|&()]+\s+)?\$(' . $names . ')\b/';
	}

	/**
	 * The public fields under one of $names that the classes under lib
	 * declare, each as `File.php: $field`.
	 *
	 * @return list<string>
	 */
	private static function publicFieldsUnder(string $names): array {
		$found = [];
		$scanned = 0;
		$root = dirname(__DIR__, 3) . '/lib';
		self::assertDirectoryExists($root);
		/** @var iterable<\SplFileInfo> $files */
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$scanned++;
			$source = (string)file_get_contents($file->getPathname());
			if (preg_match_all(self::aPublicField($names), $source, $matches) > 0) {
				$found[] = $file->getFilename() . ': $' . implode(', $', $matches[1]);
			}
		}
		// Not skipped in silence: a scan that sees nothing finds nothing.
		self::assertGreaterThan(100, $scanned, 'the scan has to see the app');
		return $found;
	}
}
