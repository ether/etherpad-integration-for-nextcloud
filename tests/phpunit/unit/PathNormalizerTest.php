<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use InvalidArgumentException;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
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

	/**
	 * This used to collapse " .pad" to ".pad", for sources said to add a
	 * stray space. But "demo .pad" is a name Nextcloud accepts, so the
	 * collapse quietly pointed at a different file — the same substitution
	 * as `C++.pad` becoming `C.pad`. Names are passed through; whether one
	 * is allowed is the storage's decision, asked when the file is created.
	 */
	public function testKeepsASpaceBeforeThePadExtension(): void {
		$this->assertSame(
			'/Folder/demo .pad',
			(new PathNormalizer())->normalizeViewerFilePath('/Folder/demo .pad')
		);
	}

	public function testKeepsSpacesInsideASegment(): void {
		$this->assertSame(
			'/A/ B .pad',
			(new PathNormalizer())->normalizeViewerFilePath('/A/ B .pad')
		);
	}

	/**
	 * The remaining trim was the same defect one step further out: it
	 * changed the name rather than deciding whether one was given.
	 * Nextcloud's FilenameValidator trims only to answer "empty?" and
	 * "." / "..", and accepts a leading or trailing space otherwise.
	 */
	public function testKeepsALeadingSpaceInABareName(): void {
		$this->assertSame(
			'/ Notes.pad',
			(new PathNormalizer())->normalizeViewerFilePath(' Notes.pad')
		);
	}

	public function testKeepsATrailingSpaceBeforeTheAppendedExtension(): void {
		$this->assertSame(
			'/Notes .pad',
			(new PathNormalizer())->normalizeCreatePath('/Notes ')
		);
	}

	public function testKeepsALeadingSpaceInAPublicShareName(): void {
		$this->assertSame(
			' Notes.pad',
			(new PathNormalizer())->normalizePublicShareFilePath(' Notes.pad', 'tok')
		);
	}

	public function testKeepsALeadingSpaceInACreateFileName(): void {
		$this->assertSame(
			' Notes.pad',
			(new PathNormalizer())->normalizeCreateFileName(' Notes')
		);
	}

	/**
	 * A URL is not a name: the scheme test has to see past padding, or a
	 * padded DAV URL is read as a relative path and can only 404.
	 */
	public function testDecodesADavUrlWithSurroundingWhitespace(): void {
		$this->assertSame(
			'/Notes.pad',
			(new PathNormalizer())->normalizeViewerFilePath('  https://host/remote.php/dav/files/alice/Notes.pad  ')
		);
	}

	public function testDecodesAPublicDavUrlWithSurroundingWhitespace(): void {
		$this->assertSame(
			'Notes.pad',
			(new PathNormalizer())->normalizePublicShareFilePath(' https://host/public.php/dav/files/tok/Notes.pad ', 'tok')
		);
	}

	/**
	 * Nextcloud trims before comparing a name against "." and "..", so
	 * " .." is as invalid as "..". The create side already refused it; the
	 * open side used to accept it as a literal segment.
	 */
	public function testRefusesAPaddedDotSegment(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new PathNormalizer())->normalizeViewerFilePath('/Folder/ ../Notes.pad');
	}

	public function testRefusesAPaddedSingleDotSegment(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new PathNormalizer())->normalizePublicShareFilePath('. /Notes.pad', 'tok');
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

	/**
	 * The case that separates the two decoders. `%2B` proves nothing — both
	 * turn it into `+`. A literal `+` in the URL is where they differ:
	 * urldecode() reads it as a space, rawurldecode() leaves it alone.
	 */
	public function testKeepsALiteralPlusInADavUrl(): void {
		$normalizer = new PathNormalizer();

		$this->assertSame(
			'/Meetings/C++.pad',
			$normalizer->normalizeViewerFilePath('https://nc.test/remote.php/dav/files/alice/Meetings/C++.pad'),
		);
		$this->assertSame(
			'Meetings/C++.pad',
			$normalizer->normalizePublicShareFilePath(
				'https://nc.test/public.php/dav/files/token123/Meetings/C++.pad',
				'token123',
			),
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

	// ------------------------------------------------------------------
	// What reaches no file, and the URL forms each entry point reads
	// ------------------------------------------------------------------

	/** Every segment of these collapses away, so no file is named; a malformed URL names none either. */
	#[DataProvider('pathsNamingNoFile')]
	public function testRefusesAViewerPathThatNamesNoFile(string $given): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file path.');

		(new PathNormalizer())->normalizeViewerFilePath($given);
	}

	/** @return array<string, array{string}> */
	public static function pathsNamingNoFile(): array {
		return [
			'a slash' => ['/'],
			'dots and slashes' => ['/././/'],
			'a malformed URL' => ['https://host:bad/Notes.pad'],
		];
	}

	public function testKeepsWhatFollowsAnEmptyOrDotSegment(): void {
		$normalizer = new PathNormalizer();

		$this->assertSame('/Folder/Notes.pad', $normalizer->normalizeViewerFilePath('/Folder//./Notes.pad'));
		$this->assertSame('Folder/Notes.pad', $normalizer->normalizePublicShareFilePath('Folder//./Notes.pad', 'tok'));
	}

	public function testReadsAUrlWhoseSchemeIsInCapitals(): void {
		$normalizer = new PathNormalizer();

		$this->assertSame('/Notes.pad', $normalizer->normalizeViewerFilePath('HTTPS://host/remote.php/dav/files/alice/Notes.pad'));
		$this->assertSame('Notes.pad', $normalizer->normalizePublicShareFilePath('HTTPS://host/public.php/dav/files/tok/Notes.pad', 'tok'));
	}

	/**
	 * Only a value that starts with a URL is read as one. A value with a
	 * URL further in is a path like any other: its slashes separate
	 * folders, and its percent signs stay as they are.
	 */
	public function testReadsAUrlOnlyAtTheStart(): void {
		$normalizer = new PathNormalizer();

		$this->assertSame('/Links/see https:/host/100%25.pad', $normalizer->normalizeViewerFilePath('/Links/see https://host/100%25.pad'));
		$this->assertSame('Links/see https:/host/100%25.pad', $normalizer->normalizePublicShareFilePath('Links/see https://host/100%25.pad', 'tok'));
	}

	public function testReadsAnyOtherUrlInAPublicShareByItsPath(): void {
		$this->assertSame(
			'Folder/Notes A.pad',
			(new PathNormalizer())->normalizePublicShareFilePath('https://host/Folder/Notes%20A.pad', 'tok')
		);
	}

	public function testNamesNoFileInAPublicShareForOnlySpaces(): void {
		$this->assertSame('', (new PathNormalizer())->normalizePublicShareFilePath('   ', 'tok'));
	}

	public function testTurnsBackslashesIntoSlashesInAPublicSharePath(): void {
		$this->assertSame('Folder/Notes.pad', (new PathNormalizer())->normalizePublicShareFilePath('Folder\\Notes.pad', 'tok'));
	}

	// ------------------------------------------------------------------
	// normalizeCreateFileName: what is no name
	// ------------------------------------------------------------------

	/** Empty, "." or ".." once trimmed, or holding a separator. */
	#[DataProvider('noFileNames')]
	public function testRefusesWhatIsNoFileName(string $given): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file name.');

		(new PathNormalizer())->normalizeCreateFileName($given);
	}

	/** @return array<string, array{string}> */
	public static function noFileNames(): array {
		return [
			'empty' => [''],
			'spaces' => ['   '],
			'a dot' => ['.'],
			'two dots' => ['..'],
			'two dots padded' => [' .. '],
			'a slash' => ['Folder/Notes'],
			'a backslash' => ['Folder\\Notes'],
		];
	}
}
