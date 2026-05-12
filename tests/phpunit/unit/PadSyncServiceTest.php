<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadSyncServiceTest extends TestCase {
	public function testSyncStatusReturnsUnavailableForExternalPads(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('frontmatter')->willReturn([
			'frontmatter' => [
				'pad_id' => 'ext.remote',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'ext.remote',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.example.test/p/remote',
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(true);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'ext.remote', BindingService::ACCESS_PUBLIC);

		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService)
			->syncStatusById('alice', 138);

		$this->assertSame([
			'status' => PadSyncService::STATUS_UNAVAILABLE,
			'in_sync' => null,
			'reason' => 'external_no_revision',
		], $result);
	}

	public function testSyncStatusReportsOutOfSyncInternalPad(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('frontmatter')->willReturn([
			'frontmatter' => [
				'pad_id' => 'g.ABC$pad',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'g.ABC$pad',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'pad_url' => '',
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotRevision')->with('frontmatter')->willReturn(3);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'g.ABC$pad', BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('getRevisionsCount')
			->with('g.ABC$pad')
			->willReturn(5);

		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService, $etherpadClient)
			->syncStatusById('alice', 138);

		$this->assertSame([
			'status' => PadSyncService::STATUS_OUT_OF_SYNC,
			'in_sync' => false,
			'snapshot_rev' => 3,
			'current_rev' => 5,
		], $result);
	}

	public function testSyncExternalPadStoresOnlyTextSnapshot(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Remote.pad');
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Remote.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('frontmatter')->willReturn([
			'frontmatter' => [
				'pad_id' => 'ext.remote',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'ext.remote',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.example.test/p/remote',
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(true);
		$padFileService->expects($this->once())
			->method('getTextSnapshotForRestore')
			->with('frontmatter')
			->willReturn('previous text');
		$padFileService->expects($this->once())
			->method('getSnapshotRevision')
			->with('frontmatter')
			->willReturn(4);
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with('frontmatter', "remote text\nfrom export", '', 5, false)
			->willReturn('updated-frontmatter');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'ext.remote', BindingService::ACCESS_PUBLIC);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('normalizeAndFetchExternalPublicPadText')
			->with('https://pad.example.test/p/remote')
			->willReturn([
				'origin' => 'https://pad.example.test',
				'pad_id' => 'remote',
				'pad_url' => 'https://pad.example.test/p/remote',
				'text' => "remote text\nfrom export",
			]);

		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->expects($this->once())
			->method('putContentWithSyncLockRetry')
			->with($file, 'updated-frontmatter')
			->willReturn(1);

		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService, $etherpadClient, $lockRetryService)
			->syncById('alice', 138, true);

		$this->assertSame([
			'status' => PadSyncService::STATUS_UPDATED,
			'file_id' => 138,
			'pad_id' => 'ext.remote',
			'external' => true,
			'forced' => true,
			'snapshot_rev' => 5,
			'lock_retries' => 1,
		], $result);
	}

	private function buildService(
		?PadFileService $padFileService = null,
		?UserNodeResolver $userNodeResolver = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
		?PadFileLockRetryService $lockRetryService = null,
	): PadSyncService {
		return new PadSyncService(
			$padFileService ?? $this->createMock(PadFileService::class),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$lockRetryService ?? $this->createMock(PadFileLockRetryService::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
