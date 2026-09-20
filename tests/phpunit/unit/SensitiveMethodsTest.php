<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

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

	public function testTheApiKeyCarryingMethodsAreRegistered(): void {
		$client = SensitiveMethods::ALL[\OCA\EtherpadNextcloud\Service\EtherpadClient::class] ?? [];

		// The request path is what carries the key into a frame; everything
		// else in the list is there for content or session values.
		$this->assertContains('apiCall', $client);
		$this->assertContains('sendRequest', $client);
	}
}
