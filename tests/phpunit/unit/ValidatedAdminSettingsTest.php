<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidatedAdminSettingsTest extends TestCase {
	public static function apiHosts(): iterable {
		yield 'no API URL of its own' => ['', 'https://pad.example.test'];
		yield 'an API URL of its own' => ['http://etherpad:9001', 'http://etherpad:9001'];
	}

	#[DataProvider('apiHosts')]
	public function testCallsTheApiUrlOrElseTheBaseUrl(string $apiHost, string $called): void {
		$settings = new ValidatedAdminSettings('https://pad.example.test', $apiHost, '', null, 'key', '1.3.0', 120, true, false, '', '');

		self::assertSame($called, $settings->effectiveApiHost());
	}
}
