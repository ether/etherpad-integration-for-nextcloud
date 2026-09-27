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
	public function testDeletingOnPermanentDeleteIsOnUnlessSwitchedOff(): void {
		foreach (['yes' => true, 'no' => false] as $stored => $enabled) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->with('etherpad_nextcloud', 'delete_on_permanent_delete', 'yes')->willReturn($stored);

			$this->assertSame($enabled, $this->service($this->createMock(IConfig::class), $appConfig)->isDeleteOnPermanentDeleteEnabled(), $stored);
		}
	}

	/**
	 * The old setting is taken over once and removed: its value, unless the
	 * new one is set already; nothing to take over, nothing written.
	 */
	public function testTheOldSettingIsTakenOver(): void {
		$cases = [
			'switched off before' => [['delete_on_trash' => 'no'], ['delete_on_permanent_delete' => 'no']],
			'left on before' => [['delete_on_trash' => 'yes'], ['delete_on_permanent_delete' => 'yes']],
			'set anew already' => [['delete_on_trash' => 'no', 'delete_on_permanent_delete' => 'yes'], ['delete_on_permanent_delete' => 'yes']],
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

	/** Read as the admin API stored it, surrounding blanks aside. */
	public function testTheTestFaultIsReadTrimmed(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->with('etherpad_nextcloud', 'test_fault', '')->willReturn(" restore_read_lock\n");

		$this->assertSame('restore_read_lock', $this->service($config)->getTestFault());
	}

	private function service(IConfig $config, ?IAppConfig $appConfig = null): AppConfigService {
		return new AppConfigService($config, $this->createMock(TrustedEmbedOriginsNormalizer::class), $appConfig ?? $this->createMock(IAppConfig::class));
	}
}
