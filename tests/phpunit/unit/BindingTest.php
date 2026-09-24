<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\WaitingBinding;
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
		$row = ['file_id' => 7, 'pad_id' => 'nc-abc', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE, 'deleted_at' => 100, 'updated_at' => 200, 'file_path' => null];
		$reads = [
			'Binding' => static fn (array $r): mixed => Binding::fromRow($r),
			'WaitingBinding' => static fn (array $r): mixed => WaitingBinding::fromRow($r),
		];
		foreach ($reads as $type => $read) {
			$read($row);
			try {
				$read(array_diff_key($row, ['state' => true]));
				$this->fail($type . ' read a row without its state.');
			} catch (\UnexpectedValueException $e) {
				$this->assertStringContainsString('state', $e->getMessage(), $type);
			}
		}
		$this->expectException(\UnexpectedValueException::class);
		Binding::fromRow([...$row, 'deleted_at' => 'yesterday']);
	}

	/**
	 * Whether a deletion owed's trouble is news: no run has put the row back
	 * since it became one. Without a date there is no telling, and it is
	 * reported each time.
	 */
	public function testARowIsUntouchedUntilARunPutsItBack(): void {
		$cases = [
			'just owed' => [100, 100, true],
			'put back since' => [100, 160, false],
			'no date' => [null, 160, true],
		];
		foreach ($cases as $case => [$deletedAt, $updatedAt, $untouched]) {
			$binding = new Binding(7, 'nc-abc', BindingService::ACCESS_PUBLIC, BindingService::STATE_PENDING_DELETE, $deletedAt, $updatedAt);
			$this->assertSame($untouched, $binding->untouchedSinceOwed(), $case);
		}
	}

	/** The two states the sweep settles; an active row is none of its business. */
	public function testOnlyADeletionOwedAndAnUndecidedRestoreWait(): void {
		$states = [
			BindingService::STATE_PENDING_DELETE => true,
			BindingService::STATE_RESTORE_PENDING => true,
			BindingService::STATE_ACTIVE => false,
		];
		foreach ($states as $state => $waits) {
			$this->assertSame($waits, (new Binding(7, 'nc-abc', BindingService::ACCESS_PUBLIC, $state))->isWaiting(), $state);
		}
	}

	/** A file gone for good has no file cache row, so the joined path comes back null. */
	public function testAWaitingRowCarriesItsFilesPathOrNone(): void {
		$this->assertEquals(
			new WaitingBinding(7, 'nc-abc', BindingService::STATE_PENDING_DELETE, 'files_trashbin/files/a.pad.d1'),
			WaitingBinding::fromRow(['file_id' => 7, 'pad_id' => 'nc-abc', 'state' => BindingService::STATE_PENDING_DELETE, 'file_path' => 'files_trashbin/files/a.pad.d1']),
		);
		$this->assertNull(WaitingBinding::fromRow(['file_id' => 7, 'pad_id' => 'nc-abc', 'state' => BindingService::STATE_PENDING_DELETE, 'file_path' => null])->filePath);
	}
}
