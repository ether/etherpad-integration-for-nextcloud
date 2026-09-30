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
	 * The names a document goes by in this app, as public fields anywhere
	 * under lib. A list of names, so it knows the ones it was given: a
	 * document under a new name passes it, and the test above is where a
	 * new carrier belongs.
	 */
	public function testNoClassDeclaresADocumentAsAPublicField(): void {
		$offenders = [];
		$scanned = 0;
		$root = dirname(__DIR__, 3) . '/lib';
		$this->assertDirectoryExists($root);
		/** @var iterable<\SplFileInfo> $files */
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$scanned++;
			$source = (string)file_get_contents($file->getPathname());
			if (preg_match_all('/public\s+(?:readonly\s+)?\??(?:string|array)\s+\$(body|frontmatter|savedText|text|html|content|expectedBefore)\b/', $source, $matches) > 0) {
				$offenders[] = $file->getFilename() . ': $' . implode(', $', $matches[1]);
			}
		}

		$this->assertGreaterThan(100, $scanned, 'the scan has to see the app');
		$this->assertSame([], $offenders);
	}

	/** As encodeArg() does, without its limits on depth and length. */
	private static function asATraceShows(mixed $argument): mixed {
		if (is_object($argument)) {
			return array_map(self::asATraceShows(...), ['__class__' => $argument::class] + get_object_vars($argument));
		}
		return is_array($argument) ? array_map(self::asATraceShows(...), $argument) : $argument;
	}
}
