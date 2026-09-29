<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use PHPUnit\Framework\TestCase;

/**
 * A fetched row as the app reads it. How each column is read - an int or a
 * numeric string for an integer - is DbRows' (DbRowsTest); here, that each
 * column lands where it belongs, and what a Binding says about its row.
 */
class BindingTest extends TestCase {
	public function testReadsEachColumnIntoItsPlace(): void {
		$binding = Binding::fromRow([
			'id' => 1,
			'file_id' => 7,
			'pad_id' => 'nc-abc',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'state' => BindingService::STATE_PENDING_DELETE,
			'deleted_at' => 100,
			'created_at' => 50,
			'updated_at' => 200,
		]);

		$this->assertEquals(new Binding(7, 'nc-abc', BindingService::ACCESS_PROTECTED, BindingService::STATE_PENDING_DELETE, 100, 200), $binding);
	}

	/** Only a deletion owed has a date for it; a row without one says so, not 0. */
	public function testARowWithoutADeletionOwedHasNoDateForIt(): void {
		$binding = Binding::fromRow(['file_id' => 7, 'pad_id' => 'nc-abc', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 200]);

		$this->assertNull($binding->deletedAt);
	}

	/**
	 * Every column is NOT NULL but deleted_at, so a column missing from the
	 * row was left out of the query. Read as '' or 0, a row without its
	 * state would wait for nothing and be passed over for good; it is an
	 * error where the row is read instead.
	 */
	public function testAColumnTheQueryLeftOutIsAnError(): void {
		$row = ['file_id' => 7, 'pad_id' => 'nc-abc', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE, 'deleted_at' => 100, 'updated_at' => 200];
		Binding::fromRow($row);
		try {
			Binding::fromRow(array_diff_key($row, ['state' => true]));
			$this->fail('Read a row without its state.');
		} catch (\UnexpectedValueException $e) {
			$this->assertStringContainsString('state', $e->getMessage());
		}
		$this->expectException(\UnexpectedValueException::class);
		Binding::fromRow([...$row, 'deleted_at' => 'yesterday']);
	}

	/**
	 * Whether a refusal to delete a gone file's pad is news: no run has tried
	 * the row since the file was seen deleted.
	 */
	public function testARowIsUntouchedUntilARunPutsItBack(): void {
		$cases = [
			'just owed' => [100, 100, true],
			'put back since' => [100, 160, false],
		];
		foreach ($cases as $case => [$deletedAt, $updatedAt, $untouched]) {
			$binding = new Binding(7, 'nc-abc', BindingService::ACCESS_PUBLIC, BindingService::STATE_PENDING_DELETE, $deletedAt, $updatedAt);
			$this->assertSame($untouched, $binding->untouchedSinceOwed(), $case);
		}
	}
}
