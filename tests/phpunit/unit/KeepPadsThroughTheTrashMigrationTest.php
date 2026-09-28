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
 * sweep, and an active row stays as it is. The setting is taken over, and
 * the jobs whose classes are gone are taken off the job list.
 */
class KeepPadsThroughTheTrashMigrationTest extends TestCase {
	public function testTheRowsThatWaitedAreTakenIntoTheNewTrash(): void {
		$row = static fn (int $fileId, string $state, ?int $deletedAt = null): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => $deletedAt, 'updated_at' => 100];
		$table = new InMemoryBindingTable([
			$row(1, BindingService::STATE_PENDING_DELETE, 90),
			$row(2, BindingService::STATE_PENDING_DELETE, 90),
			$row(3, BindingService::STATE_PENDING_DELETE, 90),
			$row(4, BindingService::STATE_ACTIVE),
		], [
			['fileid' => 1, 'storage' => 1, 'path' => 'files_trashbin/files/One.pad.d90'],
			['fileid' => 2, 'storage' => 1, 'path' => 'files/Two.pad'],
			['fileid' => 4, 'storage' => 1, 'path' => 'files/Four.pad'],
		]);
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->expects($this->once())->method('takeOverDeleteOnTrash');
		$removed = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(static function (string $job) use (&$removed): void {
			$removed[] = $job;
		});

		$clock = new FixedClock(500);
		(new Version000005Date20260928120000(new BindingService($table, $clock), $clock, $appConfig, $jobList))
			->postSchemaChange($this->createMock(IOutput::class), fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class), []);

		$active = [BindingService::STATE_ACTIVE, null, 500];
		$this->assertSame([
			1 => $active,
			2 => $active,
			3 => [BindingService::STATE_PENDING_DELETE, 90, 100],
			4 => [BindingService::STATE_ACTIVE, null, 100],
		], array_map(static fn (array $r): array => [$r['state'], $r['deleted_at'], $r['updated_at']], array_column($table->rows, null, 'file_id')));
		$this->assertSame([
			'OCA\\EtherpadNextcloud\\BackgroundJob\\HotPendingDeleteRetryJob',
			'OCA\\EtherpadNextcloud\\BackgroundJob\\WarmPendingDeleteRetryJob',
			'OCA\\EtherpadNextcloud\\BackgroundJob\\ColdPendingDeleteRetryJob',
		], $removed);
	}
}
