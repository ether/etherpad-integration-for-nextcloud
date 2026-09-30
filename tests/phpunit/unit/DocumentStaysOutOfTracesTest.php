<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use OCA\EtherpadNextcloud\Service\LivePadHtml;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Tests\Support\NamesOfSecrets;
use OCA\EtherpadNextcloud\Tests\Support\ReadsAsATrace;
use PHPUnit\Framework\TestCase;

/**
 * What Nextcloud writes of an object argument when it serializes an
 * exception's trace: its class and its public fields, and those of the
 * objects in them (ExceptionSerializer::encodeArg(), by get_object_vars()).
 *
 * The objects a document travels in are arguments of most frames on a
 * pad's way, far more than a list of method names could follow. They keep
 * the document in private fields, so none of those frames carries it.
 */
class DocumentStaysOutOfTracesTest extends TestCase {
	use ReadsAsATrace;

	private const DOCUMENT = 'what the pad says';

	/** @return iterable<string, array{object, \Closure(): list<mixed>}> */
	public static function carriers(): iterable {
		$parsed = new ParsedPadFile(
			frontmatter: ['title' => self::DOCUMENT],
			body: self::DOCUMENT,
			padId: 'pad-1',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: 3,
			savedText: self::DOCUMENT,
		);
		yield 'a parsed file' => [$parsed, static fn (): array => [$parsed->frontmatter()['title'], $parsed->body(), $parsed->savedText()]];
		$snapshot = new PadSnapshot(self::DOCUMENT, self::DOCUMENT, 3);
		yield 'a snapshot' => [$snapshot, static fn (): array => [$snapshot->text(), $snapshot->html()]];
		$live = new LivePadHtml(self::DOCUMENT, false);
		yield 'a pad as it is now' => [$live, static fn (): array => [$live->html()]];
		$claim = new CreatedFileClaim('alice', 7, self::DOCUMENT);
		yield 'a template copied into a new file' => [$claim, static fn (): array => [$claim->expectedBefore()]];
	}

	/** @param \Closure(): list<mixed> $read */
	#[\PHPUnit\Framework\Attributes\DataProvider('carriers')]
	public function testACarrierShowsATraceNothingOfTheDocument(object $carrier, \Closure $read): void {
		$shown = (string)json_encode(self::asATraceShows($carrier), JSON_THROW_ON_ERROR);

		$this->assertStringContainsString(str_replace('\\', '\\\\', $carrier::class), $shown, 'the class is what a trace has of it');
		$this->assertStringNotContainsString(self::DOCUMENT, $shown);
		// And it is still there for the code that asks.
		foreach ($read() as $part) {
			$this->assertSame(self::DOCUMENT, $part);
		}
	}

	/**
	 * No field under one of a document's names that anything outside its
	 * class can read, anywhere under lib - declared public or public
	 * without the word, promoted or not. A list of names, so it knows the
	 * ones it was given: a document under a new name passes it, and the
	 * test above is where a new carrier belongs.
	 */
	public function testNoClassDeclaresADocumentAsAPublicField(): void {
		[$found, $idle] = self::publicFieldsNamed(NamesOfSecrets::DOCUMENT, []);

		$this->assertSame([], $found);
		$this->assertSame([], $idle);
	}
}
