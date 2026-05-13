<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCP\Files\File;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadBootstrapServiceTest extends TestCase {
	public function testInitializeMissingFrontmatterCreatesProtectedPadAndWritesDocument(): void {
		$fileId = 42;
		$padId = 'g.testgroup$p-abcdefghijklmnopqrst';
		$padUrl = 'https://pad.example.test/p/g.testgroup%24p-abcdefghijklmnopqrst';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with($fileId, $padId, BindingService::ACCESS_PROTECTED);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('parseLegacyOwnpadShortcut')
			->with('')
			->willReturn(null);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with($fileId, $padId, BindingService::ACCESS_PROTECTED, '', $padUrl)
			->willReturn('doc-content');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createGroup')
			->willReturn('g.testgroup');
		$etherpadClient->expects($this->once())
			->method('createGroupPad')
			->with('g.testgroup', 'p-abcdefghijklmnopqrst')
			->willReturn($padId);
		$etherpadClient->expects($this->once())
			->method('buildPadUrl')
			->with($padId)
			->willReturn($padUrl);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->expects($this->once())
			->method('generate')
			->with(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abcdefghijklmnopqrst');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('getId')->willReturn($fileId);
		$file->expects($this->once())->method('putContent')->with('doc-content');

		$service = new PadBootstrapService($bindingService, $padFileService, $etherpadClient, $secureRandom, $logger);
		$service->initializeMissingFrontmatter($file, '');
	}

	public function testInitializeMissingFrontmatterRejectsLegacyShortcut(): void {
		$fileId = 7;
		$legacy = [
			'url' => 'https://pad.example.test/p/public-pad',
			'pad_id' => 'public-pad',
		];

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('parseLegacyOwnpadShortcut')
			->willReturn($legacy);
		$padFileService->expects($this->never())->method('inferAccessModeFromPadId');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);

		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('getId')->willReturn($fileId);

		$service = new PadBootstrapService($bindingService, $padFileService, $etherpadClient, $secureRandom, $logger);

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Legacy Ownpad .pad files cannot be auto-imported.');
		$service->initializeMissingFrontmatter($file, "[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n");
	}

	public function testInitializeMissingFrontmatterCleansUpWhenWriteFails(): void {
		$fileId = 99;
		$padId = 'g.cleanup$p-abcdefghijklmnopqrst';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with($fileId, $padId, BindingService::ACCESS_PROTECTED);
		$bindingService->expects($this->once())
			->method('deleteByFileId')
			->with($fileId);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('parseLegacyOwnpadShortcut')
			->willReturn(null);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->willReturn('doc-that-fails-to-write');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createGroup')->willReturn('g.cleanup');
		$etherpadClient->expects($this->once())->method('createGroupPad')->willReturn($padId);
		$etherpadClient->expects($this->once())->method('buildPadUrl')->with($padId)->willReturn('https://pad.example.test/p/' . rawurlencode($padId));
		$etherpadClient->expects($this->once())->method('deletePad')->with($padId);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->expects($this->once())
			->method('generate')
			->with(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abcdefghijklmnopqrst');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('getId')->willReturn($fileId);
		$file->expects($this->once())
			->method('putContent')
			->with('doc-that-fails-to-write')
			->willThrowException(new \RuntimeException('write failed'));

		$service = new PadBootstrapService($bindingService, $padFileService, $etherpadClient, $secureRandom, $logger);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('write failed');
		$service->initializeMissingFrontmatter($file, '');
	}
}
