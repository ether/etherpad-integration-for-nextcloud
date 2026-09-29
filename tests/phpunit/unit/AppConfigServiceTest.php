<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\TrustedEmbedOriginsNormalizer;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AppConfigServiceTest extends TestCase {
	/** On unless the admin switched it off: only 'no' turns it off, and a value never set is on. */
	public function testDeletingThePadWithItsFileIsOnUnlessSwitchedOff(): void {
		foreach (['yes' => true, 'no' => false] as $stored => $enabled) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->with('etherpad_nextcloud', 'delete_pad_with_file', 'yes')->willReturn($stored);

			$this->assertSame($enabled, $this->service($this->createMock(IConfig::class), $appConfig)->isDeletePadWithFileEnabled(), $stored);
		}
	}

	/**
	 * The old setting is taken over once and removed: its value, unless the
	 * new one is set already; nothing to take over, nothing written.
	 */
	public function testTheOldSettingIsTakenOver(): void {
		$cases = [
			'switched off before' => [['delete_on_trash' => 'no'], ['delete_pad_with_file' => 'no']],
			'left on before' => [['delete_on_trash' => 'yes'], ['delete_pad_with_file' => 'yes']],
			'set anew already' => [['delete_on_trash' => 'no', 'delete_pad_with_file' => 'yes'], ['delete_pad_with_file' => 'yes']],
			'never set' => [[], []],
		];
		foreach ($cases as $case => [$stored, $expected]) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$stored): string {
				return $stored[$key] ?? $default;
			});
			$appConfig->method('setValueString')->willReturnCallback(static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			});
			$appConfig->method('deleteKey')->willReturnCallback(static function (string $app, string $key) use (&$stored): void {
				unset($stored[$key]);
			});

			$this->service($this->createMock(IConfig::class), $appConfig)->takeOverDeleteOnTrash();

			$this->assertSame($expected, $stored, $case);
		}
	}

	/** The test fault of 1.1.0-beta.1 goes with its faults. */
	public function testTheTestFaultOfBeta1IsDropped(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('deleteKey')->with('etherpad_nextcloud', 'test_fault');

		$this->service($this->createMock(IConfig::class), $appConfig)->dropTestFault();
	}

	/** Read as the admin API stored it, surrounding blanks aside. */
	private function service(IConfig $config, ?IAppConfig $appConfig = null): AppConfigService {
		return new AppConfigService($config, $this->createMock(TrustedEmbedOriginsNormalizer::class), $appConfig ?? $this->createMock(IAppConfig::class));
	}
}
