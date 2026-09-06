<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\PadFileType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PadFileTypeTest extends TestCase {
	public static function nameProvider(): array {
		return [
			'plain name' => ['Notes.pad', true],
			'upper case suffix' => ['NOTES.PAD', true],
			'mixed case suffix' => ['Notes.PaD', true],
			'absolute path' => ['/Folder/Notes.pad', true],
			'suffix only' => ['.pad', true],
			'other extension' => ['Notes.txt', false],
			'suffix in the middle' => ['Notes.pad.txt', false],
			'no extension' => ['Notes', false],
			'empty' => ['', false],
		];
	}

	#[DataProvider('nameProvider')]
	public function testRecognizesPadFilesByName(string $name, bool $expected): void {
		$this->assertSame($expected, PadFileType::isPad($name));
	}

	public function testBuildsAnAnchoredPatternForTheRegisteredMimeType(): void {
		$pattern = PadFileType::mimePattern();

		$this->assertSame(1, preg_match($pattern, PadFileType::MIME));
		// Anchored: a type that merely contains ours must not match, and
		// the slash has to be quoted or the pattern would not compile.
		$this->assertSame(0, preg_match($pattern, PadFileType::MIME . '+xml'));
		$this->assertSame(0, preg_match($pattern, 'x-' . PadFileType::MIME));
		$this->assertSame(0, preg_match($pattern, 'text/plain'));
	}

	public function testAppendsTheSuffixOnlyWhenItIsMissing(): void {
		$this->assertSame('Notes.pad', PadFileType::withSuffix('Notes'));
		$this->assertSame('Notes.pad', PadFileType::withSuffix('Notes.pad'));
		// The existing spelling is kept: renaming a file the user already
		// named would be a side effect of a normalization step.
		$this->assertSame('NOTES.PAD', PadFileType::withSuffix('NOTES.PAD'));
		$this->assertSame('/Folder/Notes.pad', PadFileType::withSuffix('/Folder/Notes'));
	}
}
