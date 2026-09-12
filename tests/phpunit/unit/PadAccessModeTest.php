<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Util\PadAccessMode;
use PHPUnit\Framework\TestCase;

class PadAccessModeTest extends TestCase {
	public function testReadsTheTwoModesAndRefusesAnythingElse(): void {
		$this->assertSame(PadAccessMode::Public, PadAccessMode::tryFrom('public'));
		$this->assertSame(PadAccessMode::Protected, PadAccessMode::tryFrom('protected'));
		$this->assertNull(PadAccessMode::tryFrom('Public'));
		$this->assertNull(PadAccessMode::tryFrom('external'));
		$this->assertNull(PadAccessMode::tryFrom(''));
	}

	/**
	 * The constants are what 200-odd call sites spell. Reading an enum case's
	 * value in a constant expression needs PHP 8.3, and this app declares 8.1
	 * as its floor, so they are written out and held here instead.
	 */
	public function testTheBindingConstantsNameTheSameModes(): void {
		$this->assertSame(PadAccessMode::Public->value, BindingService::ACCESS_PUBLIC);
		$this->assertSame(PadAccessMode::Protected->value, BindingService::ACCESS_PROTECTED);
	}

	/**
	 * The browser cannot import a PHP enum, so the set is spelled once more
	 * in JavaScript. That copy decides which access mode the embed launcher
	 * accepts, and a mode missing from it is refused before the server ever
	 * sees it.
	 */
	public function testTheJavaScriptCopyNamesTheSameModes(): void {
		$constants = (string)realpath(__DIR__ . '/../../../src/lib/constants.js');
		$this->assertFileExists($constants);

		$matched = preg_match(
			// Anchored to the declaration: an unanchored skip would run past
			// this statement and read some later array instead. The optional
			// wrapper is named rather than skipped over.
			'/export\s+const\s+PAD_ACCESS_MODES\s*=\s*(?:Object\.freeze\(\s*)?\[(?<values>[^\]]*)\]/',
			(string)file_get_contents($constants),
			$matches,
		);
		$this->assertSame(1, $matched, 'src/lib/constants.js must export PAD_ACCESS_MODES');

		preg_match_all('/([\'"])(?<value>.*?)\1/', $matches['values'], $found);
		$fromJs = $found['value'];
		sort($fromJs);

		$fromPhp = array_map(static fn (PadAccessMode $mode): string => $mode->value, PadAccessMode::cases());
		sort($fromPhp);

		$this->assertSame($fromPhp, $fromJs);
	}

	/**
	 * The launcher refuses a mode the list does not name, so an importer
	 * appending to it widens what every other importer accepts.
	 */
	public function testTheJavaScriptCopyCannotBeAppendedTo(): void {
		$this->assertMatchesRegularExpression(
			'/export\s+const\s+PAD_ACCESS_MODES\s*=\s*Object\.freeze\(/',
			$this->constantsJs(),
		);
	}

	/** Two defaults for one idea, agreeing today by nothing but habit. */
	public function testTheJavaScriptDefaultIsTheOneTheControllersUse(): void {
		$matched = preg_match(
			'/export\s+const\s+DEFAULT_PAD_ACCESS_MODE\s*=\s*([\'"])(?<value>.*?)\1/',
			$this->constantsJs(),
			$matches,
		);
		$this->assertSame(1, $matched, 'src/lib/constants.js must export DEFAULT_PAD_ACCESS_MODE');
		$this->assertSame(BindingService::ACCESS_PROTECTED, $matches['value']);
	}

	private function constantsJs(): string {
		$path = (string)realpath(__DIR__ . '/../../../src/lib/constants.js');
		$this->assertFileExists($path);
		return (string)file_get_contents($path);
	}
}
