<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadLifecycleOperationService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class PadLifecycleOperationServiceTest extends TestCase {
	public function testTrashByPathFormatsSkippedLifecycleResult(): void {
		$file = $this->createMock(File::class);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->expects($this->once())
			->method('normalizeViewerFilePath')
			->with('/Test.pad')
			->willReturn('/Test.pad');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Test.pad')
			->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleTrash')
			->with($file)
			->willReturn([
				'status' => LifecycleService::RESULT_SKIPPED,
				'reason' => 'delete_on_trash_disabled',
				'file_id' => 42,
			]);

		$result = (new PadLifecycleOperationService($padPaths, $userNodeResolver, $lifecycleService))
			->trashByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'delete_on_trash_disabled',
		], $result);
	}

	public function testTrashByPathFormatsTrashedLifecycleResult(): void {
		$file = $this->createMock(File::class);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeViewerFilePath')->with('/Test.pad')->willReturn('/Test.pad');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeByPath')->with('alice', '/Test.pad')->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('handleTrash')->with($file)->willReturn([
			'status' => LifecycleService::RESULT_TRASHED,
			'deleted_at' => 1234,
			'snapshot_persisted' => true,
			'delete_pending' => false,
		]);

		$result = (new PadLifecycleOperationService($padPaths, $userNodeResolver, $lifecycleService))
			->trashByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_TRASHED,
			'deleted_at' => 1234,
			'snapshot_persisted' => true,
			'delete_pending' => false,
		], $result);
	}

	public function testRestoreByPathFormatsRestoredLifecycleResult(): void {
		$file = $this->createMock(File::class);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeViewerFilePath')->with('/Test.pad')->willReturn('/Test.pad');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeByPath')->with('alice', '/Test.pad')->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('handleRestore')->with($file)->willReturn([
			'status' => LifecycleService::RESULT_RESTORED,
			'old_pad_id' => 'old-pad',
			'new_pad_id' => 'new-pad',
		]);

		$result = (new PadLifecycleOperationService($padPaths, $userNodeResolver, $lifecycleService))
			->restoreByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_RESTORED,
			'old_pad_id' => 'old-pad',
			'new_pad_id' => 'new-pad',
		], $result);
	}

	public function testRecoverByFileIdFormatsRestoredLifecycleResult(): void {
		$file = $this->createMock(File::class);
		$padPaths = $this->createMock(PathNormalizer::class);
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 42)->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('recoverFromSnapshot')->with($file)->willReturn([
			'status' => LifecycleService::RESULT_RESTORED,
			'file_id' => 42,
			'old_pad_id' => 'orphan',
			'new_pad_id' => 'fresh',
		]);

		$result = (new PadLifecycleOperationService($padPaths, $userNodeResolver, $lifecycleService))
			->recoverByFileId('alice', 42);

		$this->assertSame([
			'file_id' => 42,
			'status' => LifecycleService::RESULT_RESTORED,
			'old_pad_id' => 'orphan',
			'new_pad_id' => 'fresh',
		], $result);
	}

	public function testRecoverByFileIdFormatsSkippedLifecycleResult(): void {
		$file = $this->createMock(File::class);
		$padPaths = $this->createMock(PathNormalizer::class);
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 51)->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('recoverFromSnapshot')->with($file)->willReturn([
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'external_pad',
			'file_id' => 51,
		]);

		$result = (new PadLifecycleOperationService($padPaths, $userNodeResolver, $lifecycleService))
			->recoverByFileId('alice', 51);

		$this->assertSame([
			'file_id' => 51,
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'external_pad',
		], $result);
	}

	public function testTrashByPathRejectsEmptyPath(): void {
		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->expects($this->once())
			->method('normalizeViewerFilePath')
			->with('   ')
			->willReturn('');

		$this->expectException(\InvalidArgumentException::class);

		(new PadLifecycleOperationService(
			$padPaths,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(LifecycleService::class),
		))->trashByPath('alice', '   ');
	}
}
