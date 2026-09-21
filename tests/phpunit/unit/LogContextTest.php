<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * One rule, checked over the source rather than per call site.
 *
 * Handing an exception object to the logger hands Nextcloud every stack
 * frame's arguments, and almost everything this app passes around is a
 * credential or a document. SafeError::context() is the shape that
 * replaces it - what failed, what it said, where it came from.
 *
 * This cannot see a secret written into a context key by hand; that
 * stays a matter of reading the line.
 */
class LogContextTest extends TestCase {
	/**
	 * Either quoting, any spacing, and assignment as well as a literal - a
	 * rule that only knows one spelling is a rule the next person writes
	 * around without meaning to.
	 */
	private const CARRIES_AN_EXCEPTION = '/([\'"])exception\\1\\s*(=>|\\]\\s*=)/';

	/** Every php file the app ships, not just the ones under lib. */
	private const SEARCHED = ['lib', 'appinfo', 'templates'];

	public function testNoLoggerContextCarriesAnExceptionObject(): void {
		$offenders = [];
		$repository = dirname(__DIR__, 3);
		foreach (self::SEARCHED as $directory) {
			$root = $repository . '/' . $directory;
			if (!is_dir($root)) {
				continue;
			}
			/** @var iterable<\SplFileInfo> $files */
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
			foreach ($files as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}
				$source = (string)file_get_contents($file->getPathname());
				foreach (explode("\n", $source) as $number => $line) {
					if (preg_match(self::CARRIES_AN_EXCEPTION, $line) === 1) {
						$offenders[] = substr($file->getPathname(), strlen($repository) + 1) . ':' . ($number + 1);
					}
				}
			}
		}

		$this->assertSame([], $offenders, "Use SafeError::context() instead:\n" . implode("\n", $offenders));
	}
}
