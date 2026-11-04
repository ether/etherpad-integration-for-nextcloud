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
		yield 'the base URL again' => ['https://pad.example.test', 'https://pad.example.test', false];
		yield 'with a trailing slash' => ['https://pad.example.test/', 'https://pad.example.test', false];
		yield 'beside a base URL with one' => ['https://pad.example.test', 'https://pad.example.test/', false];
		// The API may use http where the base URL may not: its own address,
		// however close the host.
		yield 'http for https' => ['http://pad.example.test', 'https://pad.example.test', true];
		yield 'another host' => ['https://pad-api.example.test', 'https://pad.example.test', true];
		yield 'another spelling' => ['https://PAD.example.test', 'https://pad.example.test', true];
	}

	#[DataProvider('apiHosts')]
	public function testTellsAnOwnApiHostFromTheBaseUrlAgain(string $apiHost, string $baseHost, bool $own): void {
		self::assertSame($own, ValidatedAdminSettings::isOwnApiHost($apiHost, $baseHost));
	}
}
