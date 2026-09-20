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
	public function testNoLoggerContextCarriesAnExceptionObject(): void {
		$offenders = [];
		$root = dirname(__DIR__, 3) . '/lib';
		/** @var iterable<\SplFileInfo> $files */
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$source = (string)file_get_contents($file->getPathname());
			foreach (explode("\n", $source) as $number => $line) {
				if (str_contains($line, "'exception' =>")) {
					$offenders[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($number + 1);
				}
			}
		}

		$this->assertSame([], $offenders, "Use SafeError::context() instead:\n" . implode("\n", $offenders));
	}
}
