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
		$this->assertSame(PadAccessMode::Public, PadAccessMode::tryFromValue('public'));
		$this->assertSame(PadAccessMode::Protected, PadAccessMode::tryFromValue('protected'));
		$this->assertNull(PadAccessMode::tryFromValue('Public'));
		$this->assertNull(PadAccessMode::tryFromValue('external'));
		$this->assertNull(PadAccessMode::tryFromValue(''));
	}

	/** A value that is not a string is a caller's mistake, not a third mode. */
	public function testReadsANonStringAsNoMode(): void {
		$this->assertNull(PadAccessMode::tryFromValue(null));
		$this->assertNull(PadAccessMode::tryFromValue(42));
		$this->assertNull(PadAccessMode::tryFromValue(['public']));
		// Casting this one would be a fatal error rather than a miss.
		$this->assertNull(PadAccessMode::tryFromValue(new \stdClass()));
	}

	/**
	 * The constants are what 200-odd call sites spell, and a constant
	 * expression cannot read an enum case before PHP 8.2 - which this app
	 * still supports. So they are written out, and held here.
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
			'/export\s+const\s+PAD_ACCESS_MODES\s*=\s*\[(?<values>[^\]]*)\]/',
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
}
