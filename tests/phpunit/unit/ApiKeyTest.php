<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\ApiKey;
use PHPUnit\Framework\TestCase;

/**
 * The wrapper's whole purpose is one property's visibility, and nothing
 * else in the suite notices it changing: made public, every other test
 * stays green while Nextcloud's serializer prints the key again.
 */
class ApiKeyTest extends TestCase {
	public function testTheValueIsInvisibleToTheReflectionALogWouldUse(): void {
		$key = new ApiKey('sekrit-api-key-123');

		// get_object_vars() from outside a class yields its public
		// properties, which is how an exception serializer expands an
		// argument it finds in a frame.
		$this->assertSame([], get_object_vars($key));
		$this->assertStringNotContainsString('sekrit', (string)json_encode(get_object_vars($key)));
	}

	public function testItIsNotStringable(): void {
		// Interpolating it where reveal() was meant would otherwise send
		// "***" as the key and read as a credential problem that is not one.
		$this->assertFalse(method_exists(ApiKey::class, '__toString'));
	}

	public function testTheValueIsStillReachableOnPurpose(): void {
		$this->assertSame('sekrit-api-key-123', (new ApiKey('sekrit-api-key-123'))->reveal());
	}
}
