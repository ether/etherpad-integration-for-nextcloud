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

		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$service = new PadBootstrapService($bindingService, $padFileService, $etherpadClient, $secureRandom, $logger, $migration, $this->buildPadTypePolicy(true));
		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	public function testInitializeMissingFrontmatterDelegatesLegacyShortcutToMigrationService(): void {
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

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);

		$file = $this->createMock(File::class);

		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$migration->expects($this->once())
			->method('migrate')
			->with('alice', $file, $legacy);

		$service = new PadBootstrapService($bindingService, $padFileService, $etherpadClient, $secureRandom, $logger, $migration, $this->buildPadTypePolicy(true));

		$this->assertTrue(
			$service->initializeMissingFrontmatter('alice', $file, "[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n")
		);
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

		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$service = new PadBootstrapService($bindingService, $padFileService, $etherpadClient, $secureRandom, $logger, $migration, $this->buildPadTypePolicy(true));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('write failed');
		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	public function testBlankInitialisationIsRefusedWhenNoPadTypeIsEnabled(): void {
		// Without a binding this provisions a brand-new pad, so the policy
		// applies — otherwise an empty .pad plus /initialize would be a way
		// around it.
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createGroup');
		$etherpadClient->expects($this->never())->method('createGroupPad');
		$etherpadClient->expects($this->never())->method('createPad');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4321);
		$file->expects($this->never())->method('putContent');

		$service = $this->buildService($bindingService, $padFileService, $etherpadClient, $this->buildPadTypePolicy(false, false));

		$this->expectException(\OCA\EtherpadNextcloud\Exception\PadTypeDisabledException::class);

		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	public function testBlankInitialisationFallsBackToPublicWhenProtectedPadsAreDisabled(): void {
		// A .pad can arrive outside the UI (WebDAV, another integration). If
		// protected pads are off but public ones are allowed, the file must
		// still become openable instead of 403-ing forever.
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with(4321, 'nc-abcdefghijklmnopqrstuvwx', BindingService::ACCESS_PUBLIC);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);
		$padFileService->method('buildInitialDocument')->willReturn('doc-content');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createGroupPad');
		$etherpadClient->expects($this->once())->method('createPad')->with('nc-abcdefghijklmnopqrstuvwx');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/nc-abcdefghijklmnopqrstuvwx');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrstuvwx');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4321);
		$file->expects($this->once())->method('putContent')->with('doc-content');

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$secureRandom,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(false, true),
		);

		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	private function buildService(
		BindingService $bindingService,
		PadFileService $padFileService,
		EtherpadClient $etherpadClient,
		\OCA\EtherpadNextcloud\Service\PadTypePolicy $policy,
	): PadBootstrapService {
		return new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$policy,
		);
	}

	public function testExistingBindingStillInitialisesWhenProtectedPadsAreDisabled(): void {
		// The pad already exists; the setting governs creation only, so this
		// file must keep opening.
		$fileId = 4321;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn([
			'pad_id' => 'g.group$existing',
			'access_mode' => BindingService::ACCESS_PROTECTED,
		]);
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->willReturn('doc-content');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createGroupPad');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/existing');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->expects($this->once())->method('putContent')->with('doc-content');

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(false),
		);

		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	public function testLegacyMigrationStillRunsWhenProtectedPadsAreDisabled(): void {
		// Legacy Ownpad files are existing content, not new pads.
		$legacy = ['url' => 'https://pad.example.test/p/public-pad', 'pad_id' => 'public-pad'];

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn($legacy);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4321);

		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$migration->expects($this->once())->method('migrate')->with('alice', $file, $legacy);

		$service = new PadBootstrapService(
			$this->createMock(BindingService::class),
			$padFileService,
			$this->createMock(EtherpadClient::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			$migration,
			$this->buildPadTypePolicy(false),
		);

		self::assertTrue($service->initializeMissingFrontmatter(
			'alice',
			$file,
			"[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n"
		));
	}

	private function buildPadTypePolicy(bool $protectedEnabled, bool $publicEnabled = true): \OCA\EtherpadNextcloud\Service\PadTypePolicy {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				\OCA\EtherpadNextcloud\Service\PadTypePolicy::SETTING_PROTECTED => $protectedEnabled ? 'yes' : 'no',
				\OCA\EtherpadNextcloud\Service\PadTypePolicy::SETTING_PUBLIC => $publicEnabled ? 'yes' : 'no',
				default => $default,
			}
		);
		return new \OCA\EtherpadNextcloud\Service\PadTypePolicy($config);
	}
}
