<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadKeyRejectedException;
use OCA\EtherpadNextcloud\Util\Causes;
use PHPUnit\Framework\TestCase;

class CausesTest extends TestCase {
	public function testFindsTheTypeItselfOrAmongTheCauses(): void {
		$rejected = new EtherpadKeyRejectedException('Etherpad API HTTP error (401)');
		$wrapped = new \RuntimeException('outer', 0, new EtherpadClientException('middle', 0, $rejected));

		self::assertSame($rejected, Causes::find($rejected, EtherpadKeyRejectedException::class));
		self::assertSame($rejected, Causes::find($wrapped, EtherpadKeyRejectedException::class));
		self::assertNull(Causes::find(new EtherpadClientException('no key in it'), EtherpadKeyRejectedException::class));
	}
}
