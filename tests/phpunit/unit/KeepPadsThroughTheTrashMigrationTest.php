<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Migration\Version000005Date20260928120000;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The rows 1.1.0-beta.1 left waiting, taken into a trash that keeps pads:
 * a deletion owed whose file is in a trash or back in Files is an active
 * row again; a deletion owed whose file is gone for good waits for the
 * sweep, and an active row stays as it is. A restore that could not
 * finish on a `main` after beta.1, and a deletion owed without a date, go
 * the same way. The setting is taken over, and the jobs whose classes are
 * gone are taken off the job list.
 */
class KeepPadsThroughTheTrashMigrationTest extends TestCase {
	public function testTheRowsThatWaitedAreTakenIntoTheNewTrash(): void {
		$row = static fn (int $fileId, string $state, ?int $deletedAt = null): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => $deletedAt, 'updated_at' => 100];
		$table = new InMemoryBindingTable([
			$row(1, BindingService::STATE_PENDING_DELETE, 90),
			$row(2, BindingService::STATE_PENDING_DELETE, 90),
			$row(3, BindingService::STATE_PENDING_DELETE, 90),
			$row(4, BindingService::STATE_ACTIVE),
			$row(5, 'restore_pending'),
			$row(6, 'restore_pending'),
			$row(7, BindingService::STATE_PENDING_DELETE),
			$row(8, BindingService::STATE_PENDING_DELETE),
		], [
			['fileid' => 1, 'storage' => 1, 'path' => 'files_trashbin/files/One.pad.d90'],
			['fileid' => 2, 'storage' => 1, 'path' => 'files/Two.pad'],
			['fileid' => 4, 'storage' => 1, 'path' => 'files/Four.pad'],
			['fileid' => 5, 'storage' => 1, 'path' => 'files/Five.pad'],
			['fileid' => 7, 'storage' => 1, 'path' => 'files/Seven.pad'],
		]);
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->expects($this->once())->method('takeOverDeleteOnTrash');
		$appConfig->expects($this->once())->method('dropTestFault');
		$removed = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(static function (string $job) use (&$removed): void {
			$removed[] = $job;
		});

		$clock = new FixedClock(500);
		(new Version000005Date20260928120000(new BindingService($table, $clock), $clock, $appConfig, $jobList, $table))
			->postSchemaChange($this->createMock(IOutput::class), fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class), []);

		$active = [BindingService::STATE_ACTIVE, null, 500];
		$this->assertSame([
			1 => $active,
			2 => $active,
			3 => [BindingService::STATE_PENDING_DELETE, 90, 100],
			4 => [BindingService::STATE_ACTIVE, null, 100],
			5 => $active,
			6 => [BindingService::STATE_PENDING_DELETE, 500, 500],
			7 => $active,
			8 => [BindingService::STATE_PENDING_DELETE, 500, 500],
		], array_map(static fn (array $r): array => [$r['state'], $r['deleted_at'], $r['updated_at']], array_column($table->rows, null, 'file_id')));
		$this->assertSame([
			'OCA\\EtherpadNextcloud\\BackgroundJob\\HotPendingDeleteRetryJob',
			'OCA\\EtherpadNextcloud\\BackgroundJob\\WarmPendingDeleteRetryJob',
			'OCA\\EtherpadNextcloud\\BackgroundJob\\ColdPendingDeleteRetryJob',
		], $removed);
	}

	/**
	 * The index on state gives way to one on state and access mode, once:
	 * a schema that has the new one already is left as it is.
	 */
	public function testTheIndexOnStateGivesWayToOneOnStateAndAccessMode(): void {
		$table = new class {
			/** @var array<string,list<string>> */
			public array $indexes = ['ep_bind_file_uniq' => ['file_id'], 'ep_bind_state_idx' => ['state']];

			public function hasIndex(string $name): bool {
				return isset($this->indexes[$name]);
			}

			public function dropIndex(string $name): void {
				unset($this->indexes[$name]);
			}

			/** @param list<string> $columns */
			public function addIndex(array $columns, string $name): void {
				$this->indexes[$name] = $columns;
			}
		};
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('getTable')->with(BindingService::TABLE)->willReturn($table);
		$clock = new FixedClock(500);
		$bindings = new InMemoryBindingTable([], []);
		$migration = new Version000005Date20260928120000(new BindingService($bindings, $clock), $clock, $this->createMock(AppConfigService::class), $this->createMock(IJobList::class), $bindings);

		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []));
		$this->assertSame(['ep_bind_file_uniq' => ['file_id'], 'ep_bind_state_mode_idx' => ['state', 'access_mode']], $table->indexes);
		$this->assertNull($migration->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []));
	}
}
