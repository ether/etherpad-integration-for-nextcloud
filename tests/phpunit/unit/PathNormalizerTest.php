<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use InvalidArgumentException;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use PHPUnit\Framework\TestCase;

class PathNormalizerTest extends TestCase {
	// ------------------------------------------------------------------
	// normalizeViewerFilePath
	// ------------------------------------------------------------------

	public function testNormalizeViewerFilePathExtractsPathFromDavUrl(): void {
		$this->assertSame(
			'/Apps/Test/demo.pad',
			(new PathNormalizer())->normalizeViewerFilePath(
				'https://cloud.example/remote.php/dav/files/jacob/Apps/Test/demo.pad'
			)
		);
	}

	public function testNormalizeViewerFilePathLeavesPlainPathUntouchedExceptLeadingSlash(): void {
		$this->assertSame(
			'/Folder/demo.pad',
			(new PathNormalizer())->normalizeViewerFilePath('Folder/demo.pad')
		);
	}

	public function testNormalizeViewerFilePathRejectsPathTraversal(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Path traversal is not allowed.');

		(new PathNormalizer())->normalizeViewerFilePath('/Apps/../secret.pad');
	}

	public function testNormalizeViewerFilePathReturnsEmptyForEmptyInput(): void {
		$this->assertSame('', (new PathNormalizer())->normalizeViewerFilePath(''));
		$this->assertSame('', (new PathNormalizer())->normalizeViewerFilePath('   '));
	}

	public function testNormalizeViewerFilePathRejectsNonStringInput(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file parameter.');

		(new PathNormalizer())->normalizeViewerFilePath(null);
	}

	public function testNormalizeViewerFilePathConvertsBackslashesToForwardSlashes(): void {
		$this->assertSame(
			'/Folder/demo.pad',
			(new PathNormalizer())->normalizeViewerFilePath('\\Folder\\demo.pad')
		);
	}

	public function testNormalizeViewerFilePathStripsWhitespaceBeforePadExtension(): void {
		// Some sources land here with a trailing space before .pad (UI quirks);
		// the normalizer collapses that.
		$this->assertSame(
			'/Folder/demo.pad',
			(new PathNormalizer())->normalizeViewerFilePath('/Folder/demo .pad')
		);
	}

	// ------------------------------------------------------------------
	// normalizePublicShareFilePath
	// ------------------------------------------------------------------

	public function testNormalizePublicShareFilePathExtractsPathFromTokenUrl(): void {
		$this->assertSame(
			'folder/demo.pad',
			(new PathNormalizer())->normalizePublicShareFilePath(
				'https://cloud.example/public.php/dav/files/token123/folder/demo.pad',
				'token123'
			)
		);
	}

	public function testNormalizePublicShareFilePathRejectsTokenMismatch(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Share token mismatch');

		(new PathNormalizer())->normalizePublicShareFilePath(
			'https://cloud.example/public.php/dav/files/token999/folder/demo.pad',
			'token123'
		);
	}

	public function testNormalizePublicShareFilePathAcceptsPlainPath(): void {
		$this->assertSame(
			'folder/demo.pad',
			(new PathNormalizer())->normalizePublicShareFilePath('folder/demo.pad', 'token123')
		);
	}

	public function testNormalizePublicShareFilePathReturnsEmptyForEmptyInput(): void {
		$this->assertSame(
			'',
			(new PathNormalizer())->normalizePublicShareFilePath('', 'token123')
		);
	}

	public function testNormalizePublicShareFilePathRejectsNonStringInput(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file parameter.');

		(new PathNormalizer())->normalizePublicShareFilePath(null, 'token123');
	}

	// ------------------------------------------------------------------
	// normalizeCreatePath
	// ------------------------------------------------------------------

	public function testNormalizeCreatePathRejectsEmptyPath(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file path.');

		(new PathNormalizer())->normalizeCreatePath('   ');
	}

	public function testNormalizeCreatePathAppendsPadExtension(): void {
		$this->assertSame('/Notes.pad', (new PathNormalizer())->normalizeCreatePath('/Notes'));
	}
}
