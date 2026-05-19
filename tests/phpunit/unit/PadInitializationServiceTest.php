<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadInitializationService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class PadInitializationServiceTest extends TestCase {
	public function testInitializeByPathResolvesFileAndReadsContent(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->expects($this->once())
			->method('getContent')
			->willReturn('content');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->expects($this->once())
			->method('normalizeViewerFilePath')
			->with('/Existing.pad')
			->willReturn('/Existing.pad');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Existing.pad')
			->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Existing.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')
			->with('content')
			->willReturn(new ParsedPadFile(
				frontmatter: [
					'pad_id' => 'g.ABC$pad',
					'access_mode' => BindingService::ACCESS_PUBLIC,
				],
				body: '',
				padId: 'g.ABC$pad',
				accessMode: BindingService::ACCESS_PUBLIC,
				padUrl: '',
				isExternal: false,
			));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$result = (new PadInitializationService($padFileService, $padPaths, $userNodeResolver, $bootstrap))
			->initializeByPath('alice', '/Existing.pad');

		$this->assertSame(PadInitializationService::STATUS_ALREADY_INITIALIZED, $result->status);
		$this->assertSame('/Existing.pad', $result->file);
		$this->assertSame(42, $result->fileId);
	}

	public function testInitializeByIdResolvesFileAndReadsContent(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->expects($this->once())
			->method('getContent')
			->willReturn('content');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeById')
			->with('alice', 42)
			->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Existing.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')
			->with('content')
			->willReturn(new ParsedPadFile(
				frontmatter: [
					'pad_id' => 'g.ABC$pad',
					'access_mode' => BindingService::ACCESS_PUBLIC,
				],
				body: '',
				padId: 'g.ABC$pad',
				accessMode: BindingService::ACCESS_PUBLIC,
				padUrl: '',
				isExternal: false,
			));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$result = (new PadInitializationService($padFileService, $this->createMock(PathNormalizer::class), $userNodeResolver, $bootstrap))
			->initializeById('alice', 42);

		$this->assertSame(PadInitializationService::STATUS_ALREADY_INITIALIZED, $result->status);
		$this->assertSame('/Existing.pad', $result->file);
		$this->assertSame(42, $result->fileId);
	}

	public function testInitializeByPathRejectsEmptyPath(): void {
		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->expects($this->once())
			->method('normalizeViewerFilePath')
			->with('   ')
			->willReturn('');

		$this->expectException(\InvalidArgumentException::class);

		(new PadInitializationService(
			$this->createMock(PadFileService::class),
			$padPaths,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PadBootstrapService::class),
		))->initializeByPath('alice', '   ');
	}

	public function testInitializeReturnsExistingFrontmatter(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Existing.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')
			->with('content')
			->willReturn(new ParsedPadFile(
				frontmatter: [
					'pad_id' => 'g.ABC$pad',
					'access_mode' => BindingService::ACCESS_PUBLIC,
				],
				body: '',
				padId: 'g.ABC$pad',
				accessMode: BindingService::ACCESS_PUBLIC,
				padUrl: '',
				isExternal: false,
			));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$result = (new PadInitializationService($padFileService, $this->createMock(PathNormalizer::class), $userNodeResolver, $bootstrap))
			->initialize('alice', $file, 'content');

		$this->assertSame(PadInitializationService::STATUS_ALREADY_INITIALIZED, $result->status);
		$this->assertSame('/Existing.pad', $result->file);
		$this->assertSame(42, $result->fileId);
		$this->assertSame('g.ABC$pad', $result->padId);
		$this->assertSame(BindingService::ACCESS_PUBLIC, $result->accessMode);
	}

	public function testInitializeBootstrapsMissingFrontmatter(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('updated-content');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Legacy.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$parseCalls = 0;
		$padFileService->expects($this->exactly(2))
			->method('readPad')
			->willReturnCallback(static function (string $content) use (&$parseCalls): ParsedPadFile {
				$parseCalls++;
				if ($parseCalls === 1) {
					throw new MissingFrontmatterException('Missing frontmatter.');
				}
				TestCase::assertSame('updated-content', $content);
				return new ParsedPadFile(
					frontmatter: [
						'pad_id' => 'g.XYZ$pad',
						'access_mode' => BindingService::ACCESS_PROTECTED,
					],
					body: '',
					padId: 'g.XYZ$pad',
					accessMode: BindingService::ACCESS_PROTECTED,
					padUrl: '',
					isExternal: false,
				);
			});

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $file, 'legacy-content')
			->willReturn(false);

		$result = (new PadInitializationService($padFileService, $this->createMock(PathNormalizer::class), $userNodeResolver, $bootstrap))
			->initialize('alice', $file, 'legacy-content');

		$this->assertSame(PadInitializationService::STATUS_INITIALIZED, $result->status);
		$this->assertSame('/Legacy.pad', $result->file);
		$this->assertSame(42, $result->fileId);
		$this->assertSame('g.XYZ$pad', $result->padId);
		$this->assertSame(BindingService::ACCESS_PROTECTED, $result->accessMode);
	}

	public function testInitializeReportsMigratedStatusForLegacyOwnpadShortcut(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(43);
		$file->method('getContent')->willReturn('updated-content');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/LegacyShortcut.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$parseCalls = 0;
		$padFileService->expects($this->exactly(2))
			->method('readPad')
			->willReturnCallback(static function (string $content) use (&$parseCalls): ParsedPadFile {
				$parseCalls++;
				if ($parseCalls === 1) {
					throw new MissingFrontmatterException('Missing frontmatter.');
				}
				return new ParsedPadFile(
					frontmatter: [
						'pad_id' => 're-bound-pad',
						'access_mode' => BindingService::ACCESS_PUBLIC,
					],
					body: '',
					padId: 're-bound-pad',
					accessMode: BindingService::ACCESS_PUBLIC,
					padUrl: '',
					isExternal: false,
				);
			});

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willReturn(true);

		$result = (new PadInitializationService($padFileService, $this->createMock(PathNormalizer::class), $userNodeResolver, $bootstrap))
			->initialize('alice', $file, "[InternetShortcut]\nURL=https://pad.example.test/p/re-bound-pad\n");

		$this->assertSame(PadInitializationService::STATUS_MIGRATED_FROM_LEGACY, $result->status);
		$this->assertSame('re-bound-pad', $result->padId);
	}
}
