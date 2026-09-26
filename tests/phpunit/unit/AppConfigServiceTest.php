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
	public function testDeletingOnTrashIsOnUnlessSwitchedOff(): void {
		foreach (['yes' => true, 'no' => false] as $stored => $enabled) {
			$config = $this->createMock(IConfig::class);
			$config->method('getAppValue')->with('etherpad_nextcloud', 'delete_on_trash', 'yes')->willReturn($stored);

			$this->assertSame($enabled, $this->service($config)->isDeleteOnTrashEnabled(), $stored);
		}
	}

	/** Read as the admin API stored it, surrounding blanks aside. */
	public function testTheTestFaultIsReadTrimmed(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->with('etherpad_nextcloud', 'test_fault', '')->willReturn(" trash_read_lock\n");

		$this->assertSame('trash_read_lock', $this->service($config)->getTestFault());
	}

	/**
	 * The sweep of files gone for good: seven days' grace and a brake past
	 * 20 files unless set otherwise, neither below 0; the cursor and the
	 * brake as the sweep left them.
	 */
	public function testTheGoneFileSweepSettings(): void {
		$stored = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static function (string $app, string $key, int $default = 0) use (&$stored): int {
			return (int)($stored[$app . '/' . $key] ?? $default);
		});
		$appConfig->method('getValueBool')->willReturnCallback(static function (string $app, string $key, bool $default = false) use (&$stored): bool {
			return (bool)($stored[$app . '/' . $key] ?? $default);
		});
		$set = static function (string $app, string $key, int|bool $value) use (&$stored): bool {
			$stored[$app . '/' . $key] = $value;
			return true;
		};
		$appConfig->method('setValueInt')->willReturnCallback($set);
		$appConfig->method('setValueBool')->willReturnCallback($set);
		$service = $this->service($this->createMock(IConfig::class), $appConfig);

		$this->assertSame([7 * 24 * 60 * 60, 20, 0, false, 0], [$service->getGoneFileGraceSeconds(), $service->getGoneFileBrakeThreshold(), $service->getGoneFileBrakeReleasedAt(), $service->isGoneFileBrakeEngaged(), $service->getGoneFileSweepCursor()]);

		$stored = ['etherpad_nextcloud/gone_file_grace_seconds' => -5, 'etherpad_nextcloud/gone_file_brake_threshold' => -1, 'etherpad_nextcloud/gone_file_brake_released_at' => 900, 'etherpad_nextcloud/gone_file_sweep_cursor' => -3];
		$this->assertSame([0, 0, 900, 0], [$service->getGoneFileGraceSeconds(), $service->getGoneFileBrakeThreshold(), $service->getGoneFileBrakeReleasedAt(), $service->getGoneFileSweepCursor()]);

		$service->setGoneFileSweepCursor(400);
		$service->setGoneFileBrakeEngaged(true);
		$this->assertSame([400, true], [$service->getGoneFileSweepCursor(), $service->isGoneFileBrakeEngaged()]);

		$service->releaseGoneFileBrake(1200);
		$this->assertSame([1200, false], [$service->getGoneFileBrakeReleasedAt(), $service->isGoneFileBrakeEngaged()]);
	}

	private function service(IConfig $config, ?IAppConfig $appConfig = null): AppConfigService {
		return new AppConfigService($config, $this->createMock(TrustedEmbedOriginsNormalizer::class), $appConfig ?? $this->createMock(IAppConfig::class));
	}
}
