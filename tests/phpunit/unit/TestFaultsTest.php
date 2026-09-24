<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\TestFaults;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * A fault strikes only where it is asked for by name, and only on a debug
 * instance: on any other, whatever the app value says, nothing is injected.
 */
class TestFaultsTest extends TestCase {
	public function testAFaultStrikesOnlyByNameAndOnlyInDebugMode(): void {
		$cases = [
			'set, debug' => [TestFaults::TRASH_WRITE_LOCK, true, [TestFaults::TRASH_WRITE_LOCK]],
			'set, no debug' => [TestFaults::TRASH_WRITE_LOCK, false, []],
			'another one set' => [TestFaults::RESTORE_READ_LOCK, true, [TestFaults::RESTORE_READ_LOCK]],
			'none set' => ['', true, []],
		];
		foreach ($cases as $case => [$set, $debug, $striking]) {
			$testFaults = new TestFaults($this->config($debug), $this->appConfig($set));
			foreach (TestFaults::supported() as $fault) {
				$this->assertSame(in_array($fault, $striking, true), $testFaults->isActive($fault), "$case: $fault");
			}
		}
	}

	/** The admin API accepts these names and no others. */
	public function testNamesEveryFaultOnce(): void {
		$this->assertSame(
			['trash_read_lock', 'trash_write_lock', 'trash_write_fail', 'restore_read_lock', 'restore_write_lock', 'restore_write_fail'],
			TestFaults::supported(),
		);
	}

	private function config(bool $debug): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn($debug);
		return $config;
	}

	private function appConfig(string $fault): AppConfigService {
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('getTestFault')->willReturn($fault);
		return $appConfig;
	}
}
