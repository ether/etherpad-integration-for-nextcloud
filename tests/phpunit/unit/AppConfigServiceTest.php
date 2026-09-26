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

	/** The sweep's cursor, as the sweep left it; never below 0. */
	public function testTheGoneFileSweepCursor(): void {
		$stored = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static function (string $app, string $key, int $default = 0) use (&$stored): int {
			return (int)($stored[$app . '/' . $key] ?? $default);
		});
		$appConfig->method('setValueInt')->willReturnCallback(static function (string $app, string $key, int $value) use (&$stored): bool {
			$stored[$app . '/' . $key] = $value;
			return true;
		});
		$service = $this->service($this->createMock(IConfig::class), $appConfig);

		$this->assertSame(0, $service->getGoneFileSweepCursor());
		$service->setGoneFileSweepCursor(400);
		$this->assertSame(400, $service->getGoneFileSweepCursor());
		$stored['etherpad_nextcloud/gone_file_sweep_cursor'] = -3;
		$this->assertSame(0, $service->getGoneFileSweepCursor());
	}

	private function service(IConfig $config, ?IAppConfig $appConfig = null): AppConfigService {
		return new AppConfigService($config, $this->createMock(TrustedEmbedOriginsNormalizer::class), $appConfig ?? $this->createMock(IAppConfig::class));
	}
}
