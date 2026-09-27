<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Migration\Version000005Date20260928120000;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The rows that waited under a trash that deleted pads, taken into one
 * that keeps them: a deletion owed whose file is in a trash or back in
 * Files is an active row again, a restore left undecided too; a deletion
 * owed whose file is gone for good waits for the sweep, and an active row
 * stays as it is.
 */
class KeepPadsThroughTheTrashMigrationTest extends TestCase {
	public function testTheRowsThatWaitedAreTakenIntoTheNewTrash(): void {
		$row = static fn (int $fileId, string $state, ?int $deletedAt = null): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => $deletedAt, 'updated_at' => 100];
		$table = new InMemoryBindingTable([
			$row(1, BindingService::STATE_PENDING_DELETE, 90),
			$row(2, BindingService::STATE_PENDING_DELETE, 90),
			$row(3, BindingService::STATE_PENDING_DELETE, 90),
			$row(4, 'restore_pending'),
			$row(5, BindingService::STATE_ACTIVE),
		], [
			['fileid' => 1, 'storage' => 1, 'path' => 'files_trashbin/files/One.pad.d90'],
			['fileid' => 2, 'storage' => 1, 'path' => 'files/Two.pad'],
			['fileid' => 4, 'storage' => 1, 'path' => 'files/Four.pad'],
			['fileid' => 5, 'storage' => 1, 'path' => 'files/Five.pad'],
		]);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(BindingService::TABLE)->willReturn(true);

		(new Version000005Date20260928120000($table, new FixedClock(500), $this->createMock(AppConfigService::class)))
			->postSchemaChange($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []);

		$active = [BindingService::STATE_ACTIVE, null, 500];
		$this->assertSame([
			1 => $active,
			2 => $active,
			3 => [BindingService::STATE_PENDING_DELETE, 90, 100],
			4 => $active,
			5 => [BindingService::STATE_ACTIVE, null, 100],
		], array_map(static fn (array $r): array => [$r['state'], $r['deleted_at'], $r['updated_at']], array_column($table->rows, null, 'file_id')));
	}

	/**
	 * The setting is taken over whatever the table holds; without the
	 * table - an app installed fresh - there are no rows to take over.
	 */
	public function testTheSettingIsTakenOverAndNothingElseWithoutTheTable(): void {
		$table = new InMemoryBindingTable([]);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->expects($this->once())->method('takeOverDeleteOnTrash');

		(new Version000005Date20260928120000($table, new FixedClock(500), $appConfig))
			->postSchemaChange($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []);

		$this->assertSame([], $table->rows);
	}
}
