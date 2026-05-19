<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
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
		$padFileService->method('readPad')->with('frontmatter')->willReturn(new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'ext.remote',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
			body: '',
			padId: 'ext.remote',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/remote',
			isExternal: true,
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('assertConsistentMapping');

		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService)
			->syncStatusById('alice', 138);

		$this->assertSame(PadSyncService::STATUS_UNAVAILABLE, $result->status);
		$this->assertNull($result->inSync);
		$this->assertSame('external_no_revision', $result->reason);
	}

	public function testSyncStatusReportsOutOfSyncInternalPad(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('frontmatter')->willReturn(new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'g.ABC$pad',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
			body: '',
			padId: 'g.ABC$pad',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
		));
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

		$this->assertSame(PadSyncService::STATUS_OUT_OF_SYNC, $result->status);
		$this->assertFalse($result->inSync);
		$this->assertSame(3, $result->snapshotRev);
		$this->assertSame(5, $result->currentRev);
	}

	public function testSyncExternalPadStoresOnlyTextSnapshot(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Remote.pad');
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Remote.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('frontmatter')->willReturn(new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'ext.remote',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
			body: '',
			padId: 'ext.remote',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/remote',
			isExternal: true,
		));
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
		$bindingService->expects($this->never())->method('assertConsistentMapping');

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

		$this->assertSame(PadSyncService::STATUS_UPDATED, $result->status);
		$this->assertSame(138, $result->fileId);
		$this->assertSame('ext.remote', $result->padId);
		$this->assertTrue($result->external);
		$this->assertTrue($result->forced);
		$this->assertSame(5, $result->snapshotRev);
		$this->assertSame(1, $result->lockRetries);
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
