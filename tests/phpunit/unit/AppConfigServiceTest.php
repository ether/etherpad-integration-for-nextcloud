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

	/**
	 * On unless switched off; before the setting is taken over, the old
	 * key's word stands, so an admin's opt-out holds even where this code
	 * runs before its migration.
	 */
	public function testDeletingFollowsTheOldSettingUntilTakenOver(): void {
		$cases = [
			'nothing set' => [[], true],
			'new key off' => [['delete_pad_with_file' => 'no'], false],
			'new key on, old one off' => [['delete_pad_with_file' => 'yes', 'delete_on_trash' => 'no'], true],
			'only the old key, off' => [['delete_on_trash' => 'no'], false],
			'only the old key, on' => [['delete_on_trash' => 'yes'], true],
		];
		foreach ($cases as $case => [$stored, $enabled]) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $stored[$key] ?? $default);

			$this->assertSame($enabled, $this->service($this->createMock(IConfig::class), $appConfig)->isDeletePadWithFileEnabled(), $case);
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
