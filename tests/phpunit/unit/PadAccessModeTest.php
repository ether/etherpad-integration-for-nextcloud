<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PadCreateController;
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
	 * The constants cannot be derived from the enum: reading a case's value
	 * in a constant expression needs PHP 8.2 and the declared floor is 8.1.
	 * (Cases themselves are constant expressions on 8.1, which is why
	 * FALLBACK_ORDER can hold them.) So they are written out, and held here.
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
		$matched = preg_match(
			// Anchored to the declaration: an unanchored pattern runs past
			// this statement and reads some later array instead.
			'/export\s+const\s+PAD_ACCESS_MODES\s*=\s*(?:Object\.freeze\(\s*)?\[(?<values>[^\]]*)\]/',
			$this->constantsJs(),
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

	/**
	 * Read off the controller signatures rather than off the constant they
	 * happen to use, so changing either default is what fails this.
	 */
	public function testTheJavaScriptDefaultIsTheOneTheControllersUse(): void {
		$matched = preg_match(
			'/export\s+const\s+DEFAULT_PAD_ACCESS_MODE\s*=\s*([\'"])(?<value>.*?)\1/',
			$this->constantsJs(),
			$matches,
		);
		$this->assertSame(1, $matched, 'src/lib/constants.js must export DEFAULT_PAD_ACCESS_MODE');

		foreach (['create', 'createByParent'] as $method) {
			$this->assertSame(
				$matches['value'],
				$this->defaultArgumentOf($method, 'accessMode'),
				"PadCreateController::$method() defaults to a different mode than the launcher",
			);
		}
	}

	private function defaultArgumentOf(string $method, string $argument): mixed {
		$reflected = new \ReflectionMethod(PadCreateController::class, $method);
		foreach ($reflected->getParameters() as $parameter) {
			if ($parameter->getName() === $argument) {
				$this->assertTrue($parameter->isDefaultValueAvailable(), "$method(\$$argument) has no default");
				return $parameter->getDefaultValue();
			}
		}
		$this->fail("$method() has no \$$argument parameter");
	}

	private function constantsJs(): string {
		$path = (string)realpath(__DIR__ . '/../../../src/lib/constants.js');
		$this->assertFileExists($path);
		return (string)file_get_contents($path);
	}
}
