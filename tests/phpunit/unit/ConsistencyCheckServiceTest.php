<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use PHPUnit\Framework\TestCase;

/**
 * What the consistency check counts: every row whose file the file cache
 * has nothing of, and among the active ones those missing without being
 * seen leaving Files, which the sweep keeps through their grace period.
 */
class ConsistencyCheckServiceTest extends TestCase {
	public function testCountsRowsWithoutAFileAndFilesMissing(): void {
		$row = static fn (int $fileId, string $state = BindingService::STATE_ACTIVE, ?int $trashedAt = null, ?int $missingSince = null): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => null, 'updated_at' => 100, 'trashed_at' => $trashedAt, 'missing_since' => $missingSince];
		$db = new InMemoryBindingTable([
			$row(1),
			$row(2, missingSince: 100),
			$row(3, missingSince: 100),
			$row(4, trashedAt: 100, missingSince: 100),
			$row(5, BindingService::STATE_PENDING_DELETE, missingSince: 100),
			$row(6, trashedAt: 100),
		], [['fileid' => 1, 'storage' => 1, 'path' => 'files/1.pad']]);
		$config = $this->createMock(AppConfigService::class);
		$config->method('isGoneFileBrakeEngaged')->willReturn(true);

		$result = (new ConsistencyCheckService($db, $config))->run(2);

		$this->assertSame(5, $result['binding_without_file_count']);
		$this->assertSame(2, $result['missing_file_count']);
		$this->assertTrue($result['gone_file_brake_engaged']);
		$this->assertSame([2, 3], array_column($result['samples']['bindings_without_file'], 'file_id'));
	}
}
