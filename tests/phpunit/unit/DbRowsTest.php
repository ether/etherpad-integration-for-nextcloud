<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\DbRows;
use PHPUnit\Framework\TestCase;

class DbRowsTest extends TestCase {
	/** A row comes back as it was fetched; the false that ends a result is no row. */
	public function testOneTakesARowAndTheEndOfTheResult(): void {
		$this->assertSame(['file_id' => 7, 'pad_id' => 'pad'], DbRows::one(['file_id' => 7, 'pad_id' => 'pad']));
		$this->assertNull(DbRows::one(false));
		$this->assertNull(DbRows::one(null));
	}

	/** Every row from fetchAll(), as a list, and nothing that is not a row. */
	public function testAllKeepsTheRowsInOrder(): void {
		$this->assertSame(
			[['state' => 'active', 'cnt' => 2], ['state' => 'pending_delete', 'cnt' => 1]],
			DbRows::all([3 => ['state' => 'active', 'cnt' => 2], 'x' => false, 5 => ['state' => 'pending_delete', 'cnt' => 1]]),
		);
		$this->assertSame([], DbRows::all([]));
	}
}
