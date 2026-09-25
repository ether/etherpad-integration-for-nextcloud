<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\ExternalPadException;
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

	/**
	 * The one rule for a pad on another server: public, with a link. A file
	 * that says too little to reach it is the link's problem - its reason
	 * reaches the user, and no admin is warned about Etherpad.
	 */
	public function testAnExternalPadNeedsToBePublicAndLinked(): void {
		$this->assertSame('https://pad.example.org/p/remote', $this->external(BindingService::ACCESS_PUBLIC, 'https://pad.example.org/p/remote')->externalPadUrl());

		foreach ([
			'External pad metadata requires public access_mode.' => $this->external(BindingService::ACCESS_PROTECTED, 'https://pad.example.org/p/remote'),
			'External pad URL metadata is missing or invalid.' => $this->external(BindingService::ACCESS_PUBLIC, ''),
		] as $reason => $pad) {
			try {
				$pad->externalPadUrl();
				$this->fail($reason . ': no refusal.');
			} catch (ExternalPadException $e) {
				$this->assertSame($reason, $e->getMessage());
			}
		}
	}

	private function external(string $accessMode, string $padUrl): ParsedPadFile {
		return new ParsedPadFile(['pad_origin' => 'https://pad.example.org', 'remote_pad_id' => 'remote'], '', 'ext.remote', $accessMode, $padUrl, true, -1);
	}
}
