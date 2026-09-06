<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\PadFileType;
use PHPUnit\Framework\TestCase;

/**
 * The mime type is registered in one place and matched in several others,
 * and nothing else in the app compares strings against it. A copy written
 * out at one of those sites is invisible to Psalm and to every other test:
 * the constant still has its other callers, so nothing goes unused, and a
 * copy that drifts only shows up as previews quietly not being served, or
 * as the Viewer offering to open nothing.
 */
class PadFileTypeLiteralsTest extends TestCase {
	/** Directories that ship, and the one file in each allowed to spell the type out. */
	private const SCANNED = ['lib', 'src', 'templates', 'appinfo'];
	private const HOLDERS = ['lib/Util/PadFileType.php', 'src/lib/constants.js'];

	public function testTheMimeTypeIsWrittenOutOnlyWhereItIsDefined(): void {
		$offenders = [];
		foreach ($this->shippedFiles() as $relative => $absolute) {
			if (in_array($relative, self::HOLDERS, true)) {
				continue;
			}
			// Backslashes dropped first: two of the sites this guards are
			// regexes, where the type reads `application\/x-etherpad-nextcloud`
			// or, after preg_quote(), `x\-etherpad\-nextcloud`. A plain search
			// finds neither, which is how they stayed uncollapsed.
			$content = str_replace('\\', '', (string)file_get_contents($absolute));
			if (str_contains($content, PadFileType::MIME)) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Use PadFileType::MIME (or ::mimePattern(), or the MIME export in src/lib/constants.js).',
		);
	}

	/**
	 * The browser cannot import a PHP constant, so the type is spelled once
	 * more in JavaScript. That copy decides which files the Viewer offers to
	 * open: if it drifts from the registered type, clicking a pad downloads
	 * it instead, and nothing else in the suite notices.
	 */
	public function testTheJavaScriptCopyMatchesTheRegisteredType(): void {
		$constants = (string)realpath(__DIR__ . '/../../../src/lib/constants.js');
		$this->assertFileExists($constants);

		// The exported value, not the line that exports it: quote style,
		// semicolons and line endings are formatting, and a guard about mime
		// drift should not fail when a formatter changes its mind.
		$matched = preg_match(
			'/export\s+const\s+MIME\s*=\s*([\'"])(?<value>.*?)\1/',
			(string)file_get_contents($constants),
			$matches,
		);

		$this->assertSame(1, $matched, 'src/lib/constants.js must export a MIME string');
		$this->assertSame(PadFileType::MIME, $matches['value']);
	}

	/** @return array<string,string> relative path => absolute path */
	private function shippedFiles(): array {
		$root = (string)realpath(__DIR__ . '/../../..');
		$files = [];
		foreach (self::SCANNED as $directory) {
			$base = realpath($root . '/' . $directory);
			$this->assertIsString($base, $directory . ' is missing; the guard would scan nothing');
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
			foreach ($iterator as $entry) {
				if ($entry instanceof \SplFileInfo && $entry->isFile()) {
					$absolute = (string)$entry->getRealPath();
					$files[substr($absolute, strlen($root) + 1)] = $absolute;
				}
			}
		}
		ksort($files);

		// A guard that scanned nothing would pass forever.
		$this->assertGreaterThan(80, count($files));

		return $files;
	}
}
