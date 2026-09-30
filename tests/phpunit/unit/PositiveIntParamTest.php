<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\PositiveIntParam;
use PHPUnit\Framework\TestCase;

/**
 * A positive whole number, or nothing sent; anything else sent is refused,
 * not cast to another file.
 */
class PositiveIntParamTest extends TestCase {
	public function testReadsAPositiveWholeNumberStrictly(): void {
		$this->assertNull(PositiveIntParam::read(null));
		$this->assertSame(42, PositiveIntParam::read('42'));
		$this->assertSame(42, PositiveIntParam::read(42));
		foreach (['', '7x', '1.5', '1e3', '0', '-3', 0, -3, 7.0, [], true] as $sent) {
			try {
				PositiveIntParam::read($sent);
				$this->fail('taken: ' . var_export($sent, true));
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
