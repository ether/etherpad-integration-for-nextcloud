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
 * copy that drifts only shows up as previews quietly not being served.
 */
class PadFileTypeLiteralsTest extends TestCase {
	public function testTheMimeTypeIsWrittenOutOnlyWhereItIsDefined(): void {
		$holder = realpath(__DIR__ . '/../../../lib/Util/PadFileType.php');
		$offenders = [];

		foreach ($this->phpFilesUnderLib() as $file) {
			if ($file === $holder) {
				continue;
			}
			// Backslashes dropped first: the two sites this guards were
			// regexes, where the type reads `application\/x-etherpad-nextcloud`
			// or, after preg_quote(), `x\-etherpad\-nextcloud`. A plain
			// search finds neither, which is how they stayed uncollapsed.
			$content = str_replace('\\', '', (string)file_get_contents($file));
			if (str_contains($content, PadFileType::MIME)) {
				$offenders[] = substr($file, strlen((string)realpath(__DIR__ . '/../../..')) + 1);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Use PadFileType::MIME (or ::mimePattern()) instead of writing the mime type out.',
		);
	}

	/**
	 * The browser cannot import a PHP constant, so the type is spelled once
	 * more in src/lib/constants.js. That copy decides which files the Viewer
	 * offers to open: if it drifts from the registered type, clicking a pad
	 * downloads it instead, and nothing else in the suite notices.
	 */
	public function testTheJavaScriptCopyMatchesTheRegisteredType(): void {
		$root = (string)realpath(__DIR__ . '/../../..');
		$constants = $root . '/src/lib/constants.js';
		$this->assertFileExists($constants);
		$this->assertMatchesRegularExpression(
			'/^export const MIME = \'' . preg_quote(PadFileType::MIME, '/') . '\'$/m',
			(string)file_get_contents($constants),
		);

		$offenders = [];
		foreach ($this->jsFilesUnderSrc() as $file) {
			if ($file === realpath($constants)) {
				continue;
			}
			if (str_contains((string)file_get_contents($file), PadFileType::MIME)) {
				$offenders[] = substr($file, strlen($root) + 1);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Import MIME from src/lib/constants.js instead of writing the mime type out.',
		);
	}

	/** @return list<string> */
	private function jsFilesUnderSrc(): array {
		$src = (string)realpath(__DIR__ . '/../../../src');
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
		foreach ($iterator as $entry) {
			if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'js') {
				$files[] = (string)$entry->getRealPath();
			}
		}
		sort($files);
		$this->assertGreaterThan(5, count($files));

		return $files;
	}

	/** @return list<string> */
	private function phpFilesUnderLib(): array {
		$lib = (string)realpath(__DIR__ . '/../../../lib');
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib));
		foreach ($iterator as $entry) {
			if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
				$files[] = (string)$entry->getRealPath();
			}
		}
		sort($files);

		// A guard that scanned nothing would pass forever.
		$this->assertGreaterThan(50, count($files));

		return $files;
	}
}
