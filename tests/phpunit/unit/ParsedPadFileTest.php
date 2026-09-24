<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use PHPUnit\Framework\TestCase;

class ParsedPadFileTest extends TestCase {
	/**
	 * A file names an external pad by its frontmatter, or by an `ext.` id
	 * alone: one whose metadata is incomplete is still nobody's to bind. The
	 * prefix counts only at the start of the id.
	 */
	public function testAFileNamesAnExternalPadByItsFrontmatterOrItsId(): void {
		$cases = [
			'external frontmatter' => ['ext.abc', true, true],
			'ext. id, incomplete metadata' => ['ext.abc', false, true],
			'external frontmatter, other id' => ['abc', true, true],
			'managed pad' => ['abc', false, false],
			'prefix without its dot' => ['extabc', false, false],
			'prefix inside the id' => ['r-ext.abc', false, false],
		];
		foreach ($cases as $case => [$padId, $isExternal, $expected]) {
			$pad = new ParsedPadFile(
				frontmatter: [],
				body: '',
				padId: $padId,
				accessMode: BindingService::ACCESS_PUBLIC,
				padUrl: '',
				isExternal: $isExternal,
				snapshotRev: -1,
			);

			$this->assertSame($expected, $pad->namesAnExternalPad(), $case);
		}
	}
}
