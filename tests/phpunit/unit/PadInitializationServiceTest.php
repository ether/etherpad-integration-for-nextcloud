<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
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
				snapshotRev: -1,
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
				snapshotRev: -1,
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

	/** The read can fail on its own, and would then hide the real problem. */
	public function testRefusesAnUnaddressableFileBeforeReadingIt(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(0);
		$file->expects($this->never())->method('getContent');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 42)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->never())->method('readPad');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Could not resolve file ID.');

		(new PadInitializationService($padFileService, $this->createMock(PathNormalizer::class), $userNodeResolver, $this->createMock(PadBootstrapService::class)))
			->initializeById('alice', 42);
	}

	/**
	 * Only MissingFrontmatterException continues into the bootstrap. Every
	 * other format error is the file saying something the app will not
	 * reinterpret — a round-trip-unsafe frontmatter value, say — and
	 * bootstrapping it would rewrite a file the user edited by hand instead
	 * of reporting the problem.
	 */
	public function testAFormatErrorOtherThanMissingFrontmatterIsNotBootstrapped(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn(<<<PAD
			---
			format: "pad/v1"
			pad_id: "g.aaaaaaaaaaaaaaaa\$broken"
			---

			PAD);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 42)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Broken.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willThrowException(
			new PadFileFormatException('Frontmatter values must not contain a line terminator or a NUL byte.')
		);

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->expectException(PadFileFormatException::class);

		(new PadInitializationService($padFileService, $this->createMock(PathNormalizer::class), $userNodeResolver, $bootstrap))
			->initializeById('alice', 42);
	}

	public function testInitializeReturnsExistingFrontmatter(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('content');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 42)->willReturn($file);
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
				snapshotRev: -1,
			));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$result = (new PadInitializationService($padFileService, $this->createMock(PathNormalizer::class), $userNodeResolver, $bootstrap))
			->initializeById('alice', 42);

		$this->assertSame(PadInitializationService::STATUS_ALREADY_INITIALIZED, $result->status);
		$this->assertSame('/Existing.pad', $result->file);
		$this->assertSame(42, $result->fileId);
		$this->assertSame('g.ABC$pad', $result->padId);
		$this->assertSame(BindingService::ACCESS_PUBLIC, $result->accessMode);
	}

	public function testInitializeBootstrapsMissingFrontmatter(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		// Two reads, and they differ: bootstrap rewrites the file between
		// them, which is the whole reason the second one happens.
		$file->method('getContent')->willReturnOnConsecutiveCalls('legacy-content', 'updated-content');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 42)->willReturn($file);
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
					snapshotRev: -1,
				);
			});

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $file, 'legacy-content')
			->willReturn(false);

		$result = (new PadInitializationService($padFileService, $this->createMock(PathNormalizer::class), $userNodeResolver, $bootstrap))
			->initializeById('alice', 42);

		$this->assertSame(PadInitializationService::STATUS_INITIALIZED, $result->status);
		$this->assertSame('/Legacy.pad', $result->file);
		$this->assertSame(42, $result->fileId);
		$this->assertSame('g.XYZ$pad', $result->padId);
		$this->assertSame(BindingService::ACCESS_PROTECTED, $result->accessMode);
	}

	public function testInitializeReportsMigratedStatusForLegacyOwnpadShortcut(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(43);
		$file->method('getContent')->willReturnOnConsecutiveCalls(
			"[InternetShortcut]\nURL=https://pad.example.test/p/re-bound-pad\n",
			'updated-content',
		);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 43)->willReturn($file);
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
					snapshotRev: -1,
				);
			});

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willReturn(true);

		$result = (new PadInitializationService($padFileService, $this->createMock(PathNormalizer::class), $userNodeResolver, $bootstrap))
			->initializeById('alice', 43);

		$this->assertSame(PadInitializationService::STATUS_MIGRATED_FROM_LEGACY, $result->status);
		$this->assertSame('re-bound-pad', $result->padId);
	}
}
