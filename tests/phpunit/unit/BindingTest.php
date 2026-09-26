<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\FileLocation;
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
			'replaced_pad_id' => 'nc-before',
		]);

		$this->assertEquals(new Binding(7, 'nc-abc', BindingService::ACCESS_PROTECTED, BindingService::STATE_PENDING_DELETE, 100, 200, 'nc-before'), $binding);
	}

	/** Only a deletion owed has a date for it; a row without one says so, not 0. */
	public function testARowWithoutADeletionOwedHasNoDateForIt(): void {
		$binding = Binding::fromRow(['file_id' => 7, 'pad_id' => 'nc-abc', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 200, 'replaced_pad_id' => null]);

		$this->assertNull($binding->deletedAt);
		// Nor has a row that replaced no pad a pad it replaced.
		$this->assertNull($binding->replacedPadId);
	}

	/**
	 * Every column is NOT NULL but deleted_at, so a column missing from the
	 * row was left out of the query. Read as '' or 0, a row without its
	 * state would wait for nothing and be passed over for good; it is an
	 * error where the row is read instead.
	 */
	public function testAColumnTheQueryLeftOutIsAnError(): void {
		$row = ['file_id' => 7, 'pad_id' => 'nc-abc', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_PENDING_DELETE, 'deleted_at' => 100, 'updated_at' => 200, 'replaced_pad_id' => null, 'file_path' => null, 'waiting_since' => 100];
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

	/**
	 * Where a waiting row's file is, by the path the file cache has for it,
	 * with the prefixes the query narrows by. A team folder's trash on the
	 * root storage is none of the kinds a sweep asks for; one with a storage
	 * of its own, and a folder merely named like a trash, are elsewhere.
	 */
	public function testAWaitingRowKnowsWhereItsFileIs(): void {
		$paths = [
			'gone for good' => [null, FileLocation::Gone],
			'in the owner\'s trash' => ['files_trashbin/files/a.pad.d1', FileLocation::InUserTrash],
			'in a team folder\'s trash' => ['__groupfolders/trash/3/a.pad.d1', null],
			'in a team folder\'s own trash storage' => ['trash/a.pad.d1', FileLocation::Elsewhere],
			'in Files' => ['files/a.pad', FileLocation::Elsewhere],
			'in a folder named like a trash' => ['files/files_trashbin/a.pad', FileLocation::Elsewhere],
		];
		foreach ($paths as $case => [$path, $location]) {
			$this->assertSame($location, (new WaitingBinding(7, 'nc-abc', BindingService::STATE_PENDING_DELETE, $path))->location(), $case);
		}
	}

	/**
	 * A file gone for good has no file cache row, so the joined path comes
	 * back null; a row a trash before 1.1.0 wrote may have no deleted_at.
	 */
	public function testAWaitingRowCarriesItsFilesPathOrNone(): void {
		$this->assertEquals(
			new WaitingBinding(7, 'nc-abc', BindingService::STATE_PENDING_DELETE, 'files_trashbin/files/a.pad.d1', 100),
			WaitingBinding::fromRow(['file_id' => 7, 'pad_id' => 'nc-abc', 'state' => BindingService::STATE_PENDING_DELETE, 'file_path' => 'files_trashbin/files/a.pad.d1', 'waiting_since' => 100]),
		);
		$gone = WaitingBinding::fromRow(['file_id' => 7, 'pad_id' => 'nc-abc', 'state' => BindingService::STATE_PENDING_DELETE, 'file_path' => null, 'waiting_since' => null]);
		$this->assertNull($gone->filePath);
		$this->assertNull($gone->waitingSince);
	}
}
