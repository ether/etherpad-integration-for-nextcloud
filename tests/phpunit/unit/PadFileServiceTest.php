<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;

class PadFileServiceTest extends TestCase {
	// ------------------------------------------------------------------
	// Parse + serialize roundtrip happy paths
	// ------------------------------------------------------------------

	public function testBuildInitialDocumentRoundtripsAllRequiredFields(): void {
		$service = new PadFileService(new FixedClock());
		$document = $service->buildInitialDocument(
			42,
			'g.abc$demo',
			BindingService::ACCESS_PROTECTED,
		);

		$parsed = $service->parsePadFile($document);

		$this->assertSame(PadFileService::FORMAT_V1, $parsed['frontmatter']['format']);
		$this->assertSame(42, $parsed['frontmatter']['file_id']);
		$this->assertSame('g.abc$demo', $parsed['frontmatter']['pad_id']);
		$this->assertSame(BindingService::ACCESS_PROTECTED, $parsed['frontmatter']['access_mode']);
		$this->assertSame(BindingService::STATE_ACTIVE, $parsed['frontmatter']['state']);
		$this->assertNull($parsed['frontmatter']['deleted_at']);
		$this->assertSame(-1, $parsed['frontmatter']['snapshot_rev']);
		$this->assertSame('', $parsed['body']);
	}

	public function testParsePadFileNormalizesCrlfLineEndings(): void {
		$service = new PadFileService(new FixedClock());
		$document = str_replace(
			"\n",
			"\r\n",
			$service->buildInitialDocument(
				1,
				'demo-pad',
				BindingService::ACCESS_PUBLIC,
				snapshot: new PadSnapshot('body', '', 0),
			)
		);

		$parsed = $service->parsePadFile($document);

		$this->assertSame('demo-pad', $parsed['frontmatter']['pad_id']);
		$this->assertSame(
			['text' => 'body', 'html' => ''],
			$service->getSnapshotPartsFromBody($parsed['body']),
		);
	}

	public function testSerializeIncludesOptionalPadUrlOnlyWhenSet(): void {
		$service = new PadFileService(new FixedClock());
		$withPadUrl = $service->buildInitialDocument(
			10,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/demo-pad',
		);
		$withoutPadUrl = $service->buildInitialDocument(11, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$this->assertStringContainsString('pad_url: "https://pad.example.test/p/demo-pad"', $withPadUrl);
		$this->assertStringNotContainsString('pad_url:', $withoutPadUrl);
	}

	public static function strayLineTerminatorProvider(): array {
		return [
			'inside an unquoted value' => ["state: act\rive"],
			'leading an unquoted value' => ["state: \ractive"],
			'a trailing NUL on an unquoted value' => ["state: active\0"],
			// Not `active\r` on its own: a CR directly before the newline is
			// a CRLF line ending, normalised while the document is split. One
			// followed by a space is the case trim() would silently drop.
			'a trailing carriage return before spaces' => ["state: active\r "],
			'inside a key' => ["sta\rte: active"],
			'inside a quoted value' => ["state: \"act\rive\""],
		];
	}

	/**
	 * The line is checked before anything is matched or trimmed: the key
	 * pattern treats a leading \r as whitespace and trim() drops one at
	 * either end, so a later check would let the value through silently
	 * changed instead of refusing the file.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('strayLineTerminatorProvider')]
	public function testRefusesAStrayLineTerminatorAnywhereOnAFrontmatterLine(string $line): void {
		$service = new PadFileService(new FixedClock());
		$document = $service->buildInitialDocument(30, 'demo-pad', BindingService::ACCESS_PUBLIC);
		$broken = str_replace('state: "active"', $line, $document);
		$this->assertNotSame($document, $broken, 'the fixture must actually replace the state line');

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Frontmatter line 5 contains a carriage return or a NUL byte.');
		$service->parsePadFile($broken);
	}

	/**
	 * The writer escapes backslashes and double quotes; the reader undoes
	 * only those two escapes and leaves every other backslash literal. As
	 * the reader takes the first and last quote as the value's ends, the
	 * quote escape shows only in the line written, which it keeps YAML.
	 *
	 * @return array<string,array{0: string, 1: string}>
	 */
	public static function valuesThatNeedEscaping(): array {
		return [
			'quotes and lone backslashes' => [
				'https://pad.example.org/p/say-"hello"-path\\with\\slashes',
				'https://pad.example.org/p/say-\\"hello\\"-path\\\\with\\\\slashes',
			],
			'a backslash before a quote' => [
				'https://pad.example.org/p/say-\\"hello\\"',
				'https://pad.example.org/p/say-\\\\\\"hello\\\\\\"',
			],
			'two backslashes' => [
				'https://pad.example.org/p/share\\\\path',
				'https://pad.example.org/p/share\\\\\\\\path',
			],
		];
	}

	#[DataProvider('valuesThatNeedEscaping')]
	public function testQuotedStringScalarsRoundtripWithEscapes(string $padUrl, string $written): void {
		$service = new PadFileService(new FixedClock());
		$document = $service->buildInitialDocument(
			51,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			padUrl: $padUrl,
		);

		$parsed = $service->parsePadFile($document);

		$this->assertStringContainsString("\npad_url: \"" . $written . "\"\n", $document);
		$this->assertSame($padUrl, (string)$parsed['frontmatter']['pad_url']);
	}

	/**
	 * What the reader makes of a value: quotes around it are dropped, a
	 * whole number is a number, and anything else is the text as written,
	 * a quote at one end only included.
	 *
	 * @return array<string,array{0: string, 1: int|string}>
	 */
	public static function handWrittenPadIds(): array {
		return [
			'unquoted' => ['demo', 'demo'],
			'double quotes, spaces after them' => ['"demo"  ', 'demo'],
			'single quotes' => ["'demo'", 'demo'],
			'a doubled single quote inside single quotes' => ["'it''s'", "it's"],
			'a single quote at the start only' => ["'tis", "'tis"],
			'a single quote at the end only' => ["James'", "James'"],
			'double quotes, but not at the start' => ['say "hi"', 'say "hi"'],
			'a whole number' => ['42', 42],
			'a number that is not a whole one' => ['1.10', '1.10'],
		];
	}

	#[DataProvider('handWrittenPadIds')]
	public function testAHandWrittenValueReadsTheWayItIsQuoted(string $written, int|string $read): void {
		$service = new PadFileService(new FixedClock());
		$document = self::handWritten(['pad_id' => $written]);

		$this->assertSame($read, $service->parsePadFile($document)['frontmatter']['pad_id']);
		// The pad id is text, whatever the value reads as.
		$this->assertSame((string)$read, $service->readPad($document)->padId);
	}

	public function testCommentsAndBlankLinesInTheFrontmatterAreSkipped(): void {
		$service = new PadFileService(new FixedClock());
		$document = self::handWritten();
		$annotated = str_replace('format: ', "# written by hand\n\n   \n  # indented\nformat: ", $document);
		$this->assertNotSame($document, $annotated, 'the fixture must actually add the lines');

		$this->assertSame(
			$service->parsePadFile($document)['frontmatter'],
			$service->parsePadFile($annotated)['frontmatter'],
		);
	}

	/**
	 * The format's own fields are scalars, so the map sits under a field it
	 * does not know. Such a field is read, but the writer writes only the
	 * fields format v1 defines.
	 */
	public function testAFieldTheFormatDoesNotKnowIsReadButNotWrittenBack(): void {
		$service = new PadFileService(new FixedClock());
		$pad = $service->readPad(self::handWritten(['labels' => "\n  colour: \"blue\"\n  size: 3"]));

		$this->assertSame(['colour' => 'blue', 'size' => 3], $pad->frontmatter()['labels']);
		$this->assertArrayNotHasKey('labels', $service->readPad($service->withExportSnapshot($pad, new PadSnapshot('text', '', 1)))->frontmatter());
	}

	/** @return array<string,array{0: string, 1: string}> */
	public static function acceptedLinkFields(): array {
		return [
			'a pad URL with its scheme in capitals' => ['pad_url', 'HTTPS://pad.example.test/p/demo'],
			'a pad origin with its scheme in capitals' => ['pad_origin', 'HTTPS://pad.example.test'],
			'an empty pad URL' => ['pad_url', ''],
			'an empty pad origin' => ['pad_origin', ''],
		];
	}

	#[DataProvider('acceptedLinkFields')]
	public function testTakesALinkFieldInCapitalsOrEmpty(string $field, string $value): void {
		$parsed = (new PadFileService(new FixedClock()))->parsePadFile(self::handWritten([$field => '"' . $value . '"']));

		$this->assertSame($value, $parsed['frontmatter'][$field]);
	}

	public function testBuildInitialDocumentPersistsExtraFrontmatterForExternalPads(): void {
		$service = new PadFileService(new FixedClock());
		$document = $service->buildInitialDocument(
			7,
			'ext.remote-pad-id',
			BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.remote.example/p/remote-pad-id',
			extraFrontmatter: [
				'pad_origin' => 'https://pad.remote.example',
				'remote_pad_id' => 'remote-pad-id',
			]
		);

		$parsed = $service->parsePadFile($document);

		$this->assertSame('https://pad.remote.example', $parsed['frontmatter']['pad_origin']);
		$this->assertSame('remote-pad-id', $parsed['frontmatter']['remote_pad_id']);
	}

	/** An optional field the format knows is written as text when set; a null one and an unknown one are left out. */
	public function testWritesOnlyTheExtraFieldsTheFormatKnows(): void {
		$service = new PadFileService(new FixedClock());
		$written = static fn (array $extra): array => $service->parsePadFile(
			$service->buildInitialDocument(1, 'ext.remote', BindingService::ACCESS_PUBLIC, extraFrontmatter: $extra),
		)['frontmatter'];

		$this->assertSame('42', $written(['remote_pad_id' => 42])['remote_pad_id']);
		$this->assertArrayNotHasKey('remote_pad_id', $written(['remote_pad_id' => null]));
		$this->assertArrayNotHasKey('labels', $written(['labels' => 'blue']));
	}

	// ------------------------------------------------------------------
	// Parse rejection paths
	// ------------------------------------------------------------------

	public function testParsePadFileThrowsMissingFrontmatterExceptionWhenContentHasNoFrontmatter(): void {
		$this->expectException(MissingFrontmatterException::class);
		$this->expectExceptionMessage('Missing YAML frontmatter in .pad file.');

		(new PadFileService(new FixedClock()))->parsePadFile("not-a-frontmatter");
	}

	public function testADocumentMustOpenWithItsFrontmatter(): void {
		$this->expectException(MissingFrontmatterException::class);

		(new PadFileService(new FixedClock()))->parsePadFile("notes\n" . self::handWritten());
	}

	/** @return array<string,array{0: string}> */
	public static function requiredFields(): array {
		$fields = ['format', 'file_id', 'pad_id', 'access_mode', 'state', 'created_at', 'updated_at', 'snapshot_rev'];
		return array_combine($fields, array_map(static fn (string $field): array => [$field], $fields));
	}

	#[DataProvider('requiredFields')]
	public function testParsePadFileRejectsMissingRequiredKey(string $field): void {
		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Missing required frontmatter key: ' . $field);

		(new PadFileService(new FixedClock()))->parsePadFile(self::handWritten([$field => null]));
	}

	/** @return array<string,array{0: array<string,string>, 1: string}> */
	public static function refusedFields(): array {
		return [
			'another format' => [['format' => '"etherpad-nextcloud/0"'], 'Unsupported .pad format: etherpad-nextcloud/0'],
			'file id zero' => [['file_id' => '0'], 'Invalid file_id in frontmatter.'],
			'a file id that only starts with a number' => [['file_id' => '5x'], 'Invalid file_id in frontmatter.'],
			'an empty pad id' => [['pad_id' => '""'], 'Invalid pad_id in frontmatter.'],
			'a pad id holding a map' => [['pad_id' => "\n  name: \"demo\""], 'Invalid pad_id in frontmatter.'],
			'an empty creation time' => [['created_at' => '""'], 'Invalid created_at in frontmatter.'],
			'an empty update time' => [['updated_at' => '""'], 'Invalid updated_at in frontmatter.'],
			'an unknown access mode' => [['access_mode' => '"private"'], 'Invalid access_mode in frontmatter.'],
			'an unknown state' => [['state' => '"archived"'], 'Invalid state in frontmatter.'],
			'a revision that is no number' => [['snapshot_rev' => 'abc'], 'Invalid snapshot_rev in frontmatter.'],
			'a revision below -1' => [['snapshot_rev' => '-2'], 'Invalid snapshot_rev in frontmatter.'],
			'a pad URL of another scheme' => [['pad_url' => '"ftp://example.org/p/demo"'], 'Invalid pad_url in frontmatter.'],
			'a pad URL with https further in' => [['pad_url' => '"javascript:alert(1)//https://pad.example.test/p/demo"'], 'Invalid pad_url in frontmatter.'],
			'a pad URL holding a map' => [['pad_url' => "\n  href: \"https://pad.example.test/p/demo\""], 'Invalid pad_url in frontmatter.'],
			'a pad origin of another scheme' => [['pad_origin' => '"ftp://pad.example.test"'], 'Invalid pad_origin in frontmatter.'],
			'a pad origin with https further in' => [['pad_origin' => '"javascript:alert(1)//https://pad.example.test"'], 'Invalid pad_origin in frontmatter.'],
			'an empty remote pad id' => [['remote_pad_id' => '""'], 'Invalid remote_pad_id in frontmatter.'],
		];
	}

	/** @param array<string,string> $fields */
	#[DataProvider('refusedFields')]
	public function testAValueTheFormatCannotTakeIsRefused(array $fields, string $message): void {
		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage($message);

		(new PadFileService(new FixedClock()))->parsePadFile(self::handWritten($fields));
	}

	public function testParsePadFileRejectsMalformedYamlLine(): void {
		$content = str_replace("state: \"active\"\n", "state: \"active\"\n- broken\n", self::handWritten());

		$this->expectException(PadFileFormatException::class);
		// Counted from the first line after the opening `---`, as in every
		// message about a frontmatter line.
		$this->expectExceptionMessage('Invalid YAML frontmatter line 6.');

		(new PadFileService(new FixedClock()))->parsePadFile($content);
	}

	/** @return array<string,array{0: string, 1: string}> */
	public static function indentedLinesOutsideAMap(): array {
		return [
			'no map opened' => [
				str_replace("snapshot_rev: 0\n", "snapshot_rev: 0\n  colour: \"blue\"\n", self::handWritten()),
				'Invalid nested YAML structure at line 10.',
			],
			'a map closed by the field after it' => [
				str_replace("note: \"x\"\n", "note: \"x\"\n  size: 3\n", self::handWritten(['labels' => "\n  colour: \"blue\"", 'note' => '"x"'])),
				'Invalid nested YAML structure at line 13.',
			],
		];
	}

	#[DataProvider('indentedLinesOutsideAMap')]
	public function testAnIndentedLineOutsideAMapIsRefused(string $document, string $message): void {
		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage($message);

		(new PadFileService(new FixedClock()))->parsePadFile($document);
	}

	public function testAMapEntryIndentedByMoreThanTwoSpacesIsRefused(): void {
		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid YAML frontmatter line 11.');

		(new PadFileService(new FixedClock()))->parsePadFile(self::handWritten(['labels' => "\n   colour: \"blue\""]));
	}

	public function testADocumentItCouldNotReadBackIsNotWritten(): void {
		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid access_mode in frontmatter.');

		(new PadFileService(new FixedClock()))->buildInitialDocument(1, 'demo-pad', 'private');
	}

	public function testParsePadFileAcceptsLegacyStateValuesForBackwardCompat(): void {
		// Old plugin versions wrote "trashed" / "purged" before the lifecycle
		// simplification. Existing files in users' trashbins must still parse.
		$service = new PadFileService(new FixedClock());
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$trashed = str_replace('state: "active"', 'state: "trashed"', $base);
		$purged = str_replace('state: "active"', 'state: "purged"', $base);

		$this->assertSame('trashed', (string)$service->parsePadFile($trashed)['frontmatter']['state']);
		$this->assertSame('purged', (string)$service->parsePadFile($purged)['frontmatter']['state']);
	}

	// ------------------------------------------------------------------
	// Snapshot helpers
	// ------------------------------------------------------------------

	public function testWithExportSnapshotUpdatesRevisionAndBodyParts(): void {
		$service = new PadFileService(new FixedClock());
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$updated = $service->withExportSnapshot($service->readPad($base), new PadSnapshot("line-a\nline-b", '<p>line-a</p>', 7));

		$parsed = $service->readPad($updated);
		$this->assertSame(7, $parsed->frontmatter()['snapshot_rev']);
		$this->assertSame(
			['text' => "line-a\nline-b", 'html' => '<p>line-a</p>'],
			$service->getSnapshotPartsFromBody($parsed->body()),
		);
		$this->assertSame("line-a\nline-b", $parsed->savedText(), 'read once, with the file');
	}

	public function testWithExportSnapshotEmptyValuesOverwritePreviousSnapshot(): void {
		// Important product decision: there are no archive semantics in the
		// snapshot. If Etherpad content is empty, the .pad snapshot must also
		// become empty (otherwise stale content would resurface on restore).
		$service = new PadFileService(new FixedClock());
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);
		$withContent = $service->withExportSnapshot($service->readPad($base), new PadSnapshot('previous text', '<p>previous html</p>', 5));

		$cleared = $service->withExportSnapshot($service->readPad($withContent), new PadSnapshot('', '', 6));

		$parsed = $service->readPad($cleared);
		$this->assertSame(['text' => '', 'html' => ''], $service->getSnapshotPartsFromBody($parsed->body()));
		$this->assertSame(6, $parsed->frontmatter()['snapshot_rev']);
	}

	/** @return array<string,array{0: string, 1: ?string}> */
	public static function deletionTimestamps(): array {
		return [
			'a time' => ['"2026-03-07T00:00:00+00:00"', '2026-03-07T00:00:00+00:00'],
			'an empty one, written as null' => ['""', null],
		];
	}

	#[DataProvider('deletionTimestamps')]
	public function testASyncKeepsTheDeletionTimestamp(string $written, ?string $kept): void {
		$service = new PadFileService(new FixedClock());
		$trashed = $service->readPad(self::handWritten([
			'state' => '"trashed"',
			'deleted_at' => $written,
		]));

		$synced = $service->readPad($service->withExportSnapshot($trashed, new PadSnapshot('text', '', 1)));

		$this->assertSame($kept, $synced->frontmatter()['deleted_at']);
	}

	/**
	 * A snapshot with no HTML half still gets the section, empty. Without it
	 * a body could end anywhere, and then neither marker could say which
	 * occurrence of it was structure.
	 */
	public function testASnapshotWithoutHtmlStillCarriesTheSection(): void {
		$service = new PadFileService(new FixedClock());
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$textOnly = $service->withExportSnapshot($service->readPad($base), new PadSnapshot('just text', '', 1));

		$this->assertStringEndsWith("[TEXT]\njust text\n[HTML-BEGIN]\n\n[HTML-END]", $textOnly);
		$this->assertSame(
			['text' => 'just text', 'html' => ''],
			$service->getSnapshotPartsFromBody($service->readPad($textOnly)->body()),
		);
	}

	public function testTextOnlySnapshotsPreserveATrailingContentNewline(): void {
		$service = new PadFileService(new FixedClock());
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);
		$textOnly = $service->withExportSnapshot(
			$service->readPad($base),
			new PadSnapshot("just text\n", '', 1),
		);

		$this->assertSame(
			['text' => "just text\n", 'html' => ''],
			$service->getSnapshotPartsFromBody($service->readPad($textOnly)->body()),
		);
	}

	public function testBuildInitialDocumentWithSnapshotMatchesTheTwoStepItReplaces(): void {
		// The one-step form claims to write exactly what the two-step one
		// wrote, so the two are compared rather than sampled.
		$service = new PadFileService(new FixedClock());

		$oneStep = $service->buildInitialDocument(
			1,
			'g.grp$pad',
			BindingService::ACCESS_PROTECTED,
			snapshot: new PadSnapshot('hello', '<p>hello</p>', 0),
			padUrl: 'https://pad.example.test/p/x',
		);
		$twoStep = $service->withExportSnapshot(
			$service->readPad($service->buildInitialDocument(
				1,
				'g.grp$pad',
				BindingService::ACCESS_PROTECTED,
				padUrl: 'https://pad.example.test/p/x',
			)),
			new PadSnapshot('hello', '<p>hello</p>', 0),
		);

		$this->assertSame($twoStep, $oneStep);

		$parsed = $service->readPad($oneStep);
		$this->assertSame(0, $parsed->frontmatter()['snapshot_rev']);
		$this->assertSame(
			['text' => 'hello', 'html' => '<p>hello</p>'],
			$service->getSnapshotPartsFromBody($parsed->body()),
		);
	}

	/**
	 * The body is opaque: everything after the closing `---` belongs to the
	 * snapshot, including a line that happens to look like a frontmatter
	 * key. The wall-clock helper this test file used to carry pinned that
	 * in passing; the helper is gone and the property is not the helper's.
	 */
	public function testASnapshotBodyThatLooksLikeFrontmatterSurvivesTheRoundTrip(): void {
		$service = new PadFileService(new FixedClock());
		$text = "created_at: \"in the body\"\nupdated_at: \"and again\"";

		$document = $service->buildInitialDocument(
			1,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			snapshot: new PadSnapshot($text, '', 0),
		);

		$parsed = $service->readPad($document);

		$this->assertSame($text, $service->getSnapshotPartsFromBody($parsed->body())['text']);
		// And the real ones are still the ones the frontmatter carries.
		$this->assertNotSame('in the body', $parsed->frontmatter()['created_at']);
	}

	public function testBuildInitialDocumentWithoutHtmlWritesAnEmptySection(): void {
		$service = new PadFileService(new FixedClock());

		$oneStep = $service->buildInitialDocument(
			2,
			'ext.RemotePad',
			BindingService::ACCESS_PUBLIC,
			snapshot: new PadSnapshot('remote text', '', 0),
			padUrl: 'https://pad.remote.test/p/RemotePad',
			extraFrontmatter: ['pad_origin' => 'https://pad.remote.test'],
		);
		$twoStep = $service->withExportSnapshot(
			$service->readPad($service->buildInitialDocument(
				2,
				'ext.RemotePad',
				BindingService::ACCESS_PUBLIC,
				padUrl: 'https://pad.remote.test/p/RemotePad',
				extraFrontmatter: ['pad_origin' => 'https://pad.remote.test'],
			)),
			new PadSnapshot('remote text', '', 0),
		);

		$this->assertSame($twoStep, $oneStep);

		$parsed = $service->readPad($oneStep);
		$this->assertSame(0, $parsed->frontmatter()['snapshot_rev']);
		$this->assertSame(
			['text' => 'remote text', 'html' => ''],
			$service->getSnapshotPartsFromBody($parsed->body()),
		);
	}

	public function testASnapshotCannotClaimTheNoSnapshotRevision(): void {
		// -1 is how the format says "never synced". A snapshot at that
		// revision would make a never-synced pad look synced to the next
		// sync's `$snapshotRev >= $currentRev` short circuit.
		$this->expectException(\InvalidArgumentException::class);
		new PadSnapshot('text', '', -1);
	}

	public function testFrontmatterValuesThatWouldBecomeMoreKeysAreRefused(): void {
		$service = new PadFileService(new FixedClock());

		$this->expectException(PadFileFormatException::class);
		$service->buildInitialDocument(
			321,
			'ext.RemotePad',
			BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.remote.test/p/x',
			extraFrontmatter: ['remote_pad_id' => "a\npad_id: g.victim\$secret\naccess_mode: protected"],
		);
	}

	/** @return array<string,array{0: string}> */
	public static function refusedFrontmatterBytes(): array {
		return [
			'line feed' => ["\n"],
			'carriage return' => ["\r"],
			'nul' => ["\x00"],
		];
	}

	#[DataProvider('refusedFrontmatterBytes')]
	public function testOnlyBytesThatBreakTheRoundTripAreRefused(string $byte): void {
		$service = new PadFileService(new FixedClock());

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Frontmatter values must not contain a line terminator or a NUL byte.');
		$service->buildInitialDocument(1, 'p', BindingService::ACCESS_PUBLIC, extraFrontmatter: ['remote_pad_id' => 'a' . $byte . 'b']);
	}

	/** @return array<string,array{0: string, 1: string}> */
	public static function refusedBytesOnRead(): array {
		return [
			'carriage return' => ["\r", 'Frontmatter line 10 contains a carriage return or a NUL byte.'],
			'nul' => ["\x00", 'Frontmatter line 10 contains a carriage return or a NUL byte.'],
			// A line feed ends the line: what follows it is a line of its own, and no field.
			'line feed' => ["\n", 'Invalid YAML frontmatter line 11.'],
		];
	}

	#[DataProvider('refusedBytesOnRead')]
	public function testAFileAlreadyHoldingARefusedByteIsRefusedOnRead(string $byte, string $message): void {
		$service = new PadFileService(new FixedClock());

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage($message);
		$service->readPad(self::handWritten(['remote_pad_id' => '"a' . $byte . 'b"']));
	}

	/** @return array<string,array{0: string}> */
	public static function survivingFrontmatterBytes(): array {
		return [
			'start of heading' => ["\x01"],
			'vertical tab' => ["\x0B"],
			'form feed' => ["\x0C"],
			'unit separator' => ["\x1F"],
			'delete' => ["\x7F"],
		];
	}

	#[DataProvider('survivingFrontmatterBytes')]
	public function testAControlCharacterThatCannotBreakTheRoundTripSurvivesIt(string $byte): void {
		$service = new PadFileService(new FixedClock());

		$document = $service->buildInitialDocument(
			1,
			'p',
			BindingService::ACCESS_PUBLIC,
			extraFrontmatter: ['remote_pad_id' => 'a' . $byte . 'b'],
		);

		$this->assertSame('a' . $byte . 'b', $service->readPad($document)->frontmatter()['remote_pad_id']);
	}

	#[DataProvider('survivingFrontmatterBytes')]
	public function testAStoredPadCarryingSuchAByteCanStillBeSynced(string $byte): void {
		$service = new PadFileService(new FixedClock());
		$padUrl = 'https://pad.example.test/p/de' . $byte . 'mo';

		$stored = $service->buildInitialDocument(1, 'demo', BindingService::ACCESS_PUBLIC, padUrl: $padUrl);
		$pad = $service->readPad($stored);
		$this->assertSame($padUrl, $pad->padUrl());

		$synced = $service->withExportSnapshot($pad, new PadSnapshot('text', '<p>text</p>', 1));

		$this->assertSame($padUrl, $service->readPad($synced)->padUrl());
	}

	/**
	 * A document as a hand-edited file may hold it, each value as it stands
	 * after the colon: a field named here replaces the one of that name,
	 * null drops it, and a new one goes after the rest. A value that starts
	 * on the next line opens a map.
	 *
	 * @param array<string,?string> $fields
	 */
	private static function handWritten(array $fields = []): string {
		$lines = array_merge([
			'format' => '"etherpad-nextcloud/1"',
			'file_id' => '1',
			'pad_id' => '"demo"',
			'access_mode' => '"public"',
			'state' => '"active"',
			'deleted_at' => 'null',
			'created_at' => '"2026-03-06T00:00:00+00:00"',
			'updated_at' => '"2026-03-06T00:00:00+00:00"',
			'snapshot_rev' => '0',
		], $fields);

		$document = "---\n";
		foreach ($lines as $key => $value) {
			if ($value !== null) {
				$document .= $key . ':' . (str_starts_with($value, "\n") ? '' : ' ') . $value . "\n";
			}
		}
		return $document . "---\n";
	}

	public function testGetSnapshotPartsFromBodyHandlesBodyWithoutMarkers(): void {
		$service = new PadFileService(new FixedClock());
		$parts = $service->getSnapshotPartsFromBody('raw text without sections');

		$this->assertSame('raw text without sections', $parts['text']);
		$this->assertSame('', $parts['html']);
	}

	/**
	 * A body from before every snapshot carried both sections is all text:
	 * without the terminator there is no section, whatever opening marker
	 * it holds.
	 */
	public function testABodyThatDoesNotEndInTheTerminatorIsAllText(): void {
		$this->assertSame(
			['text' => "legacy text\n[HTML-BEGIN]\nmore text\n", 'html' => ''],
			(new PadFileService(new FixedClock()))->getSnapshotPartsFromBody("[TEXT]\nlegacy text\n[HTML-BEGIN]\nmore text\n"),
		);
	}

	public function testSnapshotSectionsAllowAFileEndingNewline(): void {
		$service = new PadFileService(new FixedClock());
		$document = $service->buildInitialDocument(
			1,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			new PadSnapshot('plain text', '<p>HTML</p>', 1),
		) . "\n";

		$this->assertSame(
			['text' => 'plain text', 'html' => '<p>HTML</p>'],
			$service->getSnapshotPartsFromBody($service->readPad($document)->body()),
		);
	}

	public static function toleratedHtmlSectionEndings(): array {
		return [
			'one file-ending newline' => ["\n"],
			'two file-ending newlines' => ["\n\n"],
			'space after the terminal marker' => [' '],
		];
	}

	/**
	 * Whitespace an editor left after the terminal marker is not part of the
	 * snapshot, and refusing the file over it would throw away the HTML the
	 * section does carry.
	 */
	#[DataProvider('toleratedHtmlSectionEndings')]
	public function testSnapshotSectionsTolerateWhitespaceAfterTheTerminalMarker(string $ending): void {
		$service = new PadFileService(new FixedClock());
		$document = $service->buildInitialDocument(
			1,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			new PadSnapshot('plain text', '<p>HTML</p>', 1),
		) . $ending;

		$parts = $service->getSnapshotPartsFromBody($service->readPad($document)->body());

		$this->assertSame('plain text', $parts['text']);
		$this->assertSame('<p>HTML</p>', $parts['html']);
	}

	/**
	 * Neither marker is what says a section is there: both are lines a pad's
	 * own text may hold, and a text-only snapshot that happens to end in the
	 * terminator must not lose everything that came before it.
	 */
	public function testSnapshotTextMayEndWithTheTerminalMarker(): void {
		$service = new PadFileService(new FixedClock());

		$this->assertSame(
			['text' => "plain text\n[HTML-END]", 'html' => ''],
			$service->getSnapshotPartsFromBody("[TEXT]\nplain text\n[HTML-END]"),
		);
	}

	/**
	 * Both markers are lines a pad's text may contain. Every snapshot the
	 * writer stores carries an HTML section, so the last opening marker is
	 * the structural one, and the two shapes cannot collide.
	 */
	public function testATextHoldingBothMarkersIsNotReadAsAnHtmlSection(): void {
		$service = new PadFileService(new FixedClock());
		$text = "hallo\n[HTML-BEGIN]\nwelt\n[HTML-END]";

		$textOnly = $service->buildInitialDocument(
			1,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			new PadSnapshot($text, '', 1),
		);
		$withHtml = $service->buildInitialDocument(
			1,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			new PadSnapshot('hallo', 'welt', 1),
		);

		$this->assertNotSame($textOnly, $withHtml);
		$this->assertSame(
			['text' => $text, 'html' => ''],
			$service->getSnapshotPartsFromBody($service->readPad($textOnly)->body()),
		);
	}

	/**
	 * The documented limit of a delimiter format: the split takes the last
	 * opening marker, so an HTML half carrying one on a line of its own takes
	 * the split with it. Etherpad assembles its export without line
	 * separators, so nothing this app fetches produces that shape - a
	 * hand-written HTML half can, and is read the way the markers say.
	 */
	public function testAnHtmlHalfCarryingAMarkerLineTakesTheSplitWithIt(): void {
		$service = new PadFileService(new FixedClock());

		$document = $service->buildInitialDocument(
			1,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			new PadSnapshot('hallo', "<p>x</p>\n[HTML-BEGIN]\n<p>y</p>", 1),
		);
		$parts = $service->getSnapshotPartsFromBody($service->readPad($document)->body());

		$this->assertSame("hallo\n[HTML-BEGIN]\n<p>x</p>", $parts['text']);
		$this->assertSame('<p>y</p>', $parts['html']);
	}

	/** A pad whose own text holds that line keeps all of it. */
	public function testSnapshotTextMayContainTheOpeningMarker(): void {
		$service = new PadFileService(new FixedClock());
		$text = "hello\n[HTML-BEGIN]\nworld";

		$document = $service->buildInitialDocument(
			1,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			new PadSnapshot($text, '<p>HTML</p>', 1),
		);
		$parts = $service->getSnapshotPartsFromBody($service->readPad($document)->body());

		$this->assertSame($text, $parts['text']);
		$this->assertSame('<p>HTML</p>', $parts['html']);
	}

	public function testWithRestoredSnapshotWritesTheRestoreInvariant(): void {
		$service = new PadFileService(new FixedClock());
		$trashed = $service->readPad($service->withExportSnapshot(
			$service->readPad($service->buildInitialDocument(1, 'old-pad', BindingService::ACCESS_PUBLIC)),
			new PadSnapshot('original text', '<p>original html</p>', 4),
		));

		$restored = $service->readPad($service->withRestoredSnapshot(
			$trashed,
			'replaced text',
			'<p>replaced html</p>',
			'new-pad',
			'https://pad.example.test/p/new-pad',
		));

		// Active and undeleted are not the caller's to get wrong any more.
		$this->assertSame(BindingService::STATE_ACTIVE, $restored->frontmatter()['state']);
		$this->assertNull($restored->frontmatter()['deleted_at']);
		$this->assertSame('new-pad', $restored->padId);
		$this->assertSame('https://pad.example.test/p/new-pad', $restored->padUrl());
		// The old pad's revision 4 means nothing to the new one: without the
		// new pad's own count, the document has not been synced yet.
		$this->assertSame(-1, $restored->snapshotRev);
		$this->assertSame(2, $service->readPad($service->withRestoredSnapshot(
			$trashed,
			'replaced text',
			'<p>replaced html</p>',
			'new-pad',
			'https://pad.example.test/p/new-pad',
			2,
		))->snapshotRev);
		$this->assertSame(
			['text' => 'replaced text', 'html' => '<p>replaced html</p>'],
			$service->getSnapshotPartsFromBody($restored->body()),
		);
	}

	/**
	 * -1 is the lowest revision the format takes, and the writer refuses
	 * anything below: without the clamp, a restore given a lower count
	 * would fail.
	 */
	public function testARestoreNeverRecordsARevisionBelowNotSynced(): void {
		$service = new PadFileService(new FixedClock());

		$restored = $service->withRestoredSnapshot($service->readPad(self::handWritten()), 'text', '', 'new-pad', '', -5);

		$this->assertSame(-1, $service->readPad($restored)->snapshotRev);
	}

	public function testTheParsedDocumentCarriesTheSnapshotRevision(): void {
		$service = new PadFileService(new FixedClock());
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$this->assertSame(-1, $service->readPad($base)->snapshotRev);
		$this->assertSame(12, $service->readPad(str_replace('snapshot_rev: -1', 'snapshot_rev: 12', $base))->snapshotRev);
	}

	// ------------------------------------------------------------------
	// Legacy ownpad + metadata helpers
	// ------------------------------------------------------------------

	/** @return array<string,array{0: string, 1: string, 2: string}> */
	public static function legacyShortcuts(): array {
		return [
			'as Ownpad wrote it' => [
				"[InternetShortcut]\nURL=https://pad.example.test/p/public-pad-id\n",
				'https://pad.example.test/p/public-pad-id',
				'public-pad-id',
			],
			'section and key in another case' => [
				"[internetshortcut]\nurl=https://pad.example.test/p/demo\n",
				'https://pad.example.test/p/demo',
				'demo',
			],
			// BASEURL, as Windows writes it under [DEFAULT]: a line of its own, not URL=.
			'a BASEURL line before it' => [
				"[DEFAULT]\nBASEURL=https://other.example.test/p/other\n[InternetShortcut]\nURL=https://pad.example.test/p/demo\n",
				'https://pad.example.test/p/demo',
				'demo',
			],
			'whitespace around the URL' => [
				"[InternetShortcut]\nURL = https://pad.example.test/p/demo \t\n",
				'https://pad.example.test/p/demo',
				'demo',
			],
			'the scheme in upper case' => [
				"[InternetShortcut]\nURL=HTTPS://pad.example.test/p/demo\n",
				'HTTPS://pad.example.test/p/demo',
				'demo',
			],
			'an Etherpad under a path with a p segment of its own' => [
				"[InternetShortcut]\nURL=https://example.test/p/etherpad/p/demo\n",
				'https://example.test/p/etherpad/p/demo',
				'demo',
			],
			// A plus stays a plus: only form encoding reads it as a space.
			'a plus in the pad name' => [
				"[InternetShortcut]\nURL=https://pad.example.test/p/team+meeting\n",
				'https://pad.example.test/p/team+meeting',
				'team+meeting',
			],
			'a percent-encoded plus' => [
				"[InternetShortcut]\nURL=https://pad.example.test/p/team%2Bmeeting\n",
				'https://pad.example.test/p/team%2Bmeeting',
				'team+meeting',
			],
			// A hand-written file does not hide the id it names from the
			// rules on the id.
			'an id encoded and padded by hand' => [
				"[InternetShortcut]\nURL=https://pad.example.test/p/%20nc%2Dabc%20\n",
				'https://pad.example.test/p/%20nc%2Dabc%20',
				'nc-abc',
			],
		];
	}

	#[DataProvider('legacyShortcuts')]
	public function testParseLegacyOwnpadShortcutExtractsUrlAndPadId(string $content, string $url, string $padId): void {
		$this->assertSame(
			['url' => $url, 'pad_id' => $padId],
			(new PadFileService(new FixedClock()))->parseLegacyOwnpadShortcut($content),
		);
	}

	/** @return array<string,array{0: string}> */
	public static function notLegacyShortcuts(): array {
		return [
			'not a shortcut' => ['not a shortcut'],
			'nothing' => [''],
			'a URL without the section' => ["URL=https://pad.example.test/p/demo\n"],
			'the section name inside other text' => ["see [InternetShortcut]\nURL=https://pad.example.test/p/demo\n"],
			'the section name with more after it' => ["[InternetShortcut] more\nURL=https://pad.example.test/p/demo\n"],
			'the section without a URL' => ["[InternetShortcut]\nIconIndex=0\n"],
			'another scheme' => ["[InternetShortcut]\nURL=ftp://example.test/p/pad\n"],
			'another scheme with https further in' => ["[InternetShortcut]\nURL=ftp://mirror.example.test/https://pad.example.test/p/demo\n"],
			'a URL that does not parse' => ["[InternetShortcut]\nURL=https://pad.example.test:99999/p/demo\n"],
			'no pad segment' => ["[InternetShortcut]\nURL=https://example.test/somewhere/else\n"],
			'a pad segment of whitespace' => ["[InternetShortcut]\nURL=https://pad.example.test/p/%20\n"],
		];
	}

	#[DataProvider('notLegacyShortcuts')]
	public function testParseLegacyOwnpadShortcutReturnsNullForAnythingElse(string $content): void {
		$this->assertNull((new PadFileService(new FixedClock()))->parseLegacyOwnpadShortcut($content));
	}

	public function testInferAccessModeFromPadIdMapsGroupPadsToProtected(): void {
		$service = new PadFileService(new FixedClock());

		$this->assertSame(
			BindingService::ACCESS_PROTECTED,
			$service->inferAccessModeFromPadId('g.TmDeyA334sIq2LQh$new-pad-8')
		);
		$this->assertSame(
			BindingService::ACCESS_PUBLIC,
			$service->inferAccessModeFromPadId('ncckmrb1konwdtj6ywfnnr2f2udv8rgr87puljd6zg7wca')
		);
	}

	public function testIsExternalFrontmatterRequiresExtPrefixAndOriginAndRemoteId(): void {
		$service = new PadFileService(new FixedClock());

		$this->assertTrue($service->isExternalFrontmatter([
			'pad_origin' => 'https://pad.remote.example',
			'remote_pad_id' => 'remote-id',
		], 'ext.remote-id'));

		$this->assertFalse($service->isExternalFrontmatter([
			'pad_origin' => 'https://pad.remote.example',
		], 'ext.remote-id'));

		$this->assertFalse($service->isExternalFrontmatter([
			'remote_pad_id' => 'remote-id',
		], 'ext.remote-id'));

		$this->assertFalse($service->isExternalFrontmatter([
			'pad_origin' => 'https://pad.remote.example',
			'remote_pad_id' => ' ',
		], 'ext.remote-id'));

		$this->assertFalse($service->isExternalFrontmatter([
			'pad_origin' => ' ',
			'remote_pad_id' => 'remote-id',
		], 'ext.remote-id'));

		$this->assertFalse($service->isExternalFrontmatter([
			'pad_origin' => 'https://pad.remote.example',
			'remote_pad_id' => 'remote-id',
		], 'demo-pad'));
	}

	public function testExtractPadMetadataReturnsDefaultsForMissingFields(): void {
		$service = new PadFileService(new FixedClock());

		$this->assertSame(
			['pad_id' => '', 'access_mode' => '', 'pad_url' => ''],
			$service->extractPadMetadata([])
		);
		$this->assertSame(
			['pad_id' => 'pad-1', 'access_mode' => 'public', 'pad_url' => 'https://pad.example.test/p/pad-1'],
			$service->extractPadMetadata([
				'pad_id' => 'pad-1',
				'access_mode' => 'public',
				'pad_url' => '  https://pad.example.test/p/pad-1  ',
			])
		);
	}
}
