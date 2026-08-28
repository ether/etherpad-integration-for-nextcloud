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
	// ------------------------------------------------------------------
	// Names carrying characters that URL decoding would change
	// ------------------------------------------------------------------

	/**
	 * A request parameter is already decoded by the time a controller sees
	 * it. Decoding it again turned `+` into a space, and the trailing-space
	 * rule then swallowed it: `C++.pad` opened `C.pad` — a different file,
	 * with nothing to show that it had happened.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('namesThatDecodingWouldChange')]
	public function testLeavesAnAlreadyDecodedNameAlone(string $given, string $expected): void {
		$this->assertSame($expected, (new PathNormalizer())->normalizeViewerFilePath($given));
	}

	/** @return array<string,array{string,string}> */
	public static function namesThatDecodingWouldChange(): array {
		return [
			'plus in the name' => ['/Meetings/C++.pad', '/Meetings/C++.pad'],
			'plus between words' => ['/A + B.pad', '/A + B.pad'],
			'plus at the end' => ['/Notes+.pad', '/Notes+.pad'],
			// A file really named with a percent sequence: decoding would
			// rename it, and %25 would decode twice.
			'percent sequence' => ['/Plus%2BSign.pad', '/Plus%2BSign.pad'],
			'double-encoded percent' => ['/Odd%252B.pad', '/Odd%252B.pad'],
			'percent alone' => ['/100%.pad', '/100%.pad'],
		];
	}

	/**
	 * A full DAV URL is the one input that carries percent-encoding of its
	 * own, and rawurldecode keeps `+` a `+` while decoding `%2B`.
	 */
	public function testDecodesOnlyThePathOfADavUrl(): void {
		$normalizer = new PathNormalizer();

		$this->assertSame(
			'/Meetings/C++.pad',
			$normalizer->normalizeViewerFilePath('https://nc.test/remote.php/dav/files/alice/Meetings/C%2B%2B.pad'),
		);
		$this->assertSame(
			'/A + B.pad',
			$normalizer->normalizeViewerFilePath('https://nc.test/remote.php/dav/files/alice/A%20%2B%20B.pad'),
		);
	}

	public function testKeepsAPlusInAPublicShareePath(): void {
		$this->assertSame(
			'Meetings/C++.pad',
			(new PathNormalizer())->normalizePublicShareFilePath('/Meetings/C++.pad', 'token123'),
		);
	}

	public function testKeepsAPlusWhenAppendingTheExtension(): void {
		$this->assertSame('/C++.pad', (new PathNormalizer())->normalizeCreatePath('/C++'));
	}

	public function testKeepsAPlusInABareFileName(): void {
		$this->assertSame('C++.pad', (new PathNormalizer())->normalizeCreateFileName('C++'));
	}
}
