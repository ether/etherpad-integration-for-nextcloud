<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\TrustedEmbedOriginsNormalizer;
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

	private function service(IConfig $config): AppConfigService {
		return new AppConfigService($config, $this->createMock(TrustedEmbedOriginsNormalizer::class));
	}
}
