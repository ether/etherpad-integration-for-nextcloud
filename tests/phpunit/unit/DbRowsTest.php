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

	/** An integer column as the driver gave it, an int or a numeric string; a string column as it is. */
	public function testReadsAColumnAsWhatItHolds(): void {
		$row = ['file_id' => '7', 'updated_at' => 200, 'deleted_at' => null, 'pad_id' => 'nc-abc', 'file_path' => null];

		$this->assertSame(7, DbRows::int($row, 'file_id'));
		$this->assertSame(200, DbRows::int($row, 'updated_at'));
		$this->assertNull(DbRows::nullableInt($row, 'deleted_at'));
		$this->assertSame('nc-abc', DbRows::string($row, 'pad_id'));
		$this->assertNull(DbRows::nullableString($row, 'file_path'));
	}

	/**
	 * A column the query did not select, null where the column is NOT NULL,
	 * or a value of another kind: none is read as data.
	 */
	public function testRefusesWhatAColumnCannotHold(): void {
		$reads = [
			'not selected' => static fn (): mixed => DbRows::nullableInt([], 'deleted_at'),
			'null in an int' => static fn (): mixed => DbRows::int(['file_id' => null], 'file_id'),
			'null in a string' => static fn (): mixed => DbRows::string(['pad_id' => null], 'pad_id'),
			'not a number' => static fn (): mixed => DbRows::int(['file_id' => '7a'], 'file_id'),
			'not a string' => static fn (): mixed => DbRows::string(['pad_id' => 7], 'pad_id'),
		];
		foreach ($reads as $case => $read) {
			try {
				$read();
				$this->fail('Read: ' . $case);
			} catch (\UnexpectedValueException) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
