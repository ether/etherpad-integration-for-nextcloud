<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\StoredAdminSettings;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCA\EtherpadNextcloud\Util\SensitiveMethods;
use PHPUnit\Framework\TestCase;

/**
 * The registration is a list of names, and a list of names goes stale in
 * silence: rename a method and Nextcloud simply stops replacing its
 * arguments, with nothing to show for it but a secret in a log nobody
 * reads until they need it.
 */
class SensitiveMethodsTest extends TestCase {
	/** @return iterable<string, array{class-string, string}> */
	public static function registeredMethodProvider(): iterable {
		foreach (SensitiveMethods::ALL as $class => $methods) {
			foreach ($methods as $method) {
				yield $class . '::' . $method => [$class, $method];
			}
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('registeredMethodProvider')]
	public function testEveryRegisteredMethodExists(string $class, string $method): void {
		$this->assertTrue(class_exists($class), $class . ' is registered but does not exist');
		$this->assertTrue(
			method_exists($class, $method),
			$class . '::' . $method . ' is registered as sensitive but does not exist',
		);
	}

	/**
	 * The api key is on no list, because no frame holds it: it travels as
	 * ApiKey and the request body leaves as a stream. That only holds while
	 * the settings objects keep it out of get_object_vars(), which is what
	 * a serialized trace reads.
	 */
	public function testTheSettingsObjectsDoNotExposeTheApiKey(): void {
		$validated = new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad-api.example.test',
			'.example.test',
			'secret-to-store',
			'secret-in-use',
			'1.3.0',
			120,
			true,
			false,
			'',
			'',
		);
		$stored = new StoredAdminSettings('stored-secret', '.example.test', true, false, '');

		$this->assertStringNotContainsString('secret', (string)json_encode(get_object_vars($validated)));
		$this->assertStringNotContainsString('secret', (string)json_encode(get_object_vars($stored)));
		// And they are still reachable where they are needed.
		$this->assertSame('secret-in-use', $validated->effectiveApiKey()->reveal());
		$this->assertSame('stored-secret', $stored->apiKey()->reveal());
	}
}
