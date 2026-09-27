<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use PHPUnit\Framework\TestCase;

/**
 * What the consistency check counts: every row whose file the file cache
 * has nothing of, and among them the vanished ones - still active, never
 * seen deleted for good - which the app leaves to an admin.
 */
class ConsistencyCheckServiceTest extends TestCase {
	public function testCountsRowsWithoutAFileAndTheVanishedAmongThem(): void {
		$row = static fn (int $fileId, string $state = BindingService::STATE_ACTIVE): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => $state, 'deleted_at' => $state === BindingService::STATE_ACTIVE ? null : 90, 'updated_at' => 100];
		$db = new InMemoryBindingTable([
			$row(1),
			$row(2, BindingService::STATE_PENDING_DELETE),
			$row(3),
			$row(4, BindingService::STATE_PENDING_DELETE),
			$row(5),
			$row(6),
		], [['fileid' => 1, 'storage' => 1, 'path' => 'files/1.pad']]);

		$result = (new ConsistencyCheckService($db))->run(2);

		$this->assertSame(5, $result['binding_without_file_count']);
		$this->assertSame(3, $result['vanished_file_count'], 'not the ones seen deleted for good');
		$this->assertSame([2, 3], array_column($result['samples']['bindings_without_file'], 'file_id'));
		$this->assertSame([['file_id' => 3, 'pad_id' => 'pad-3', 'access_mode' => BindingService::ACCESS_PUBLIC], ['file_id' => 5, 'pad_id' => 'pad-5', 'access_mode' => BindingService::ACCESS_PUBLIC]], $result['samples']['vanished_files']);
	}
}
