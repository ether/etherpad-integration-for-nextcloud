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
				snapshot: new PadSnapshot('body', null, 0),
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

	/**
	 * `serialize()` writes a closed set of keys, so a marker that is not
	 * named there would be dropped by the next rewrite that touches the
	 * file.
	 */
	public function testAliasMarkerSurvivesASerializeRoundTrip(): void {
		$service = new PadFileService(new FixedClock());
		$document = $service->buildInitialDocument(
			20,
			'original-pad',
			BindingService::ACCESS_PROTECTED,
			extraFrontmatter: ['alias_of_pad_id' => 'original-pad'],
		);

		$parsed = $service->readPad($document);

		$this->assertStringContainsString('alias_of_pad_id: "original-pad"', $document);
		$this->assertSame('original-pad', $parsed->aliasOfPadId);
		$this->assertSame('original-pad', $service->readPad($service->serialize($parsed->frontmatter, $parsed->body))->aliasOfPadId);
	}

	public function testAPadFileWithoutTheMarkerReportsNoAlias(): void {
		$service = new PadFileService(new FixedClock());
		$document = $service->buildInitialDocument(21, 'demo-pad', BindingService::ACCESS_PROTECTED);

		$this->assertStringNotContainsString('alias_of_pad_id:', $document);
		$this->assertSame('', $service->readPad($document)->aliasOfPadId);
	}

	/**
	 * A copy is a byte copy, so its own `pad_id` is already the original's.
	 * That equality is the ordinary case and must parse.
	 */
	public function testAliasMarkerMayRepeatTheFilesOwnPadId(): void {
		$service = new PadFileService(new FixedClock());
		$document = $service->buildInitialDocument(
			22,
			'shared-pad',
			BindingService::ACCESS_PROTECTED,
			extraFrontmatter: ['alias_of_pad_id' => 'shared-pad'],
		);

		$this->assertSame('shared-pad', $service->readPad($document)->aliasOfPadId);
	}

	public static function invalidAliasMarkerProvider(): array {
		return [
			'empty' => ['""'],
			'external pad' => ['"ext.remote-pad"'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidAliasMarkerProvider')]
	public function testRejectsAnUnusableAliasMarker(string $marker): void {
		$service = new PadFileService(new FixedClock());
		$valid = $service->buildInitialDocument(
			23,
			'demo-pad',
			BindingService::ACCESS_PROTECTED,
			extraFrontmatter: ['alias_of_pad_id' => 'other-pad'],
		);
		// Control: everything but the marker value is already accepted, so a
		// rejection below can only come from the marker itself.
		$this->assertSame('other-pad', $service->readPad($valid)->aliasOfPadId);

		$broken = str_replace('alias_of_pad_id: "other-pad"', 'alias_of_pad_id: ' . $marker, $valid);
		$this->assertNotSame($valid, $broken);

		$this->expectException(PadFileFormatException::class);
		$service->parsePadFile($broken);
	}

	public function testQuotedStringScalarsRoundtripWithEscapes(): void {
		$service = new PadFileService(new FixedClock());
		$padUrl = 'https://pad.example.org/p/say-"hello"-path\\with\\slashes';
		$document = $service->buildInitialDocument(
			51,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			padUrl: $padUrl,
		);

		$parsed = $service->parsePadFile($document);

		$this->assertSame($padUrl, (string)$parsed['frontmatter']['pad_url']);
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

	// ------------------------------------------------------------------
	// Parse rejection paths
	// ------------------------------------------------------------------

	public function testParsePadFileThrowsMissingFrontmatterExceptionWhenContentHasNoFrontmatter(): void {
		$this->expectException(MissingFrontmatterException::class);
		$this->expectExceptionMessage('Missing YAML frontmatter in .pad file.');

		(new PadFileService(new FixedClock()))->parsePadFile("not-a-frontmatter");
	}

	public function testParsePadFileRejectsUnsupportedFormat(): void {
		$content = "---\nformat: \"etherpad-nextcloud/0\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Unsupported .pad format');

		(new PadFileService(new FixedClock()))->parsePadFile($content);
	}

	public function testParsePadFileRejectsMissingRequiredKey(): void {
		// Missing snapshot_rev
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Missing required frontmatter key: snapshot_rev');

		(new PadFileService(new FixedClock()))->parsePadFile($content);
	}

	public function testParsePadFileRejectsInvalidSnapshotRevBelowMinusOne(): void {
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: -2\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid snapshot_rev');

		(new PadFileService(new FixedClock()))->parsePadFile($content);
	}

	public function testParsePadFileRejectsNonHttpPadUrl(): void {
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\npad_url: \"ftp://example.org/p/demo\"\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid pad_url');

		(new PadFileService(new FixedClock()))->parsePadFile($content);
	}

	public function testParsePadFileRejectsInvalidAccessMode(): void {
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"private\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid access_mode');

		(new PadFileService(new FixedClock()))->parsePadFile($content);
	}

	public function testParsePadFileRejectsMalformedYamlLine(): void {
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\n- broken\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid YAML frontmatter');

		(new PadFileService(new FixedClock()))->parsePadFile($content);
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
		$this->assertSame(7, $parsed->frontmatter['snapshot_rev']);
		$this->assertSame(
			['text' => "line-a\nline-b", 'html' => '<p>line-a</p>'],
			$service->getSnapshotPartsFromBody($parsed->body),
		);
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
		$this->assertSame(['text' => '', 'html' => ''], $service->getSnapshotPartsFromBody($parsed->body));
		$this->assertSame(6, $parsed->frontmatter['snapshot_rev']);
	}

	public function testWithExportSnapshotOmitsHtmlSectionWhenRequested(): void {
		$service = new PadFileService(new FixedClock());
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$textOnly = $service->withExportSnapshot($service->readPad($base), new PadSnapshot('just text', null, 1));

		$this->assertStringNotContainsString('[HTML-BEGIN]', $textOnly);
		$this->assertStringNotContainsString('[HTML-END]', $textOnly);
		$this->assertSame(
			['text' => 'just text', 'html' => ''],
			$service->getSnapshotPartsFromBody($service->readPad($textOnly)->body),
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
		$this->assertSame(0, $parsed->frontmatter['snapshot_rev']);
		$this->assertSame(
			['text' => 'hello', 'html' => '<p>hello</p>'],
			$service->getSnapshotPartsFromBody($parsed->body),
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
			snapshot: new PadSnapshot($text, null, 0),
		);

		$parsed = $service->readPad($document);

		$this->assertSame($text, $service->getSnapshotPartsFromBody($parsed->body)['text']);
		// And the real ones are still the ones the frontmatter carries.
		$this->assertNotSame('in the body', $parsed->frontmatter['created_at']);
	}

	public function testBuildInitialDocumentWithoutHtmlOmitsTheHtmlSection(): void {
		// snapshotHtml: null means text only, not "an empty HTML half".
		$service = new PadFileService(new FixedClock());

		$oneStep = $service->buildInitialDocument(
			2,
			'ext.RemotePad',
			BindingService::ACCESS_PUBLIC,
			snapshot: new PadSnapshot('remote text', null, 0),
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
			new PadSnapshot('remote text', null, 0),
		);

		$this->assertSame($twoStep, $oneStep);
		$this->assertStringNotContainsString('[HTML-BEGIN]', $oneStep);

		$parsed = $service->readPad($oneStep);
		$this->assertSame(0, $parsed->frontmatter['snapshot_rev']);
		$this->assertSame(
			['text' => 'remote text', 'html' => ''],
			$service->getSnapshotPartsFromBody($parsed->body),
		);
	}

	public function testASnapshotCannotClaimTheNoSnapshotRevision(): void {
		// -1 is how the format says "never synced". A snapshot at that
		// revision would make a never-synced pad look synced to the next
		// sync's `$snapshotRev >= $currentRev` short circuit.
		$this->expectException(\InvalidArgumentException::class);
		new PadSnapshot('text', null, -1);
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
		$service->buildInitialDocument(1, 'p', BindingService::ACCESS_PUBLIC, extraFrontmatter: ['remote_pad_id' => 'a' . $byte . 'b']);
	}

	#[DataProvider('refusedFrontmatterBytes')]
	public function testAFileAlreadyHoldingARefusedByteIsRefusedOnRead(string $byte): void {
		$service = new PadFileService(new FixedClock());

		$this->expectException(PadFileFormatException::class);
		$service->readPad(self::documentWithRemotePadId('a' . $byte . 'b'));
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

		$this->assertSame('a' . $byte . 'b', $service->readPad($document)->frontmatter['remote_pad_id']);
	}

	#[DataProvider('survivingFrontmatterBytes')]
	public function testAStoredPadCarryingSuchAByteCanStillBeSynced(string $byte): void {
		$service = new PadFileService(new FixedClock());
		$padUrl = 'https://pad.example.test/p/de' . $byte . 'mo';

		$stored = $service->buildInitialDocument(1, 'demo', BindingService::ACCESS_PUBLIC, padUrl: $padUrl);
		$pad = $service->readPad($stored);
		$this->assertSame($padUrl, $pad->padUrl);

		$synced = $service->withExportSnapshot($pad, new PadSnapshot('text', '<p>text</p>', 1));

		$this->assertSame($padUrl, $service->readPad($synced)->padUrl);
	}

	private static function documentWithRemotePadId(string $value): string {
		return "---\n"
			. "format: \"etherpad-nextcloud/1\"\n"
			. "file_id: 1\n"
			. "pad_id: \"demo\"\n"
			. "access_mode: \"public\"\n"
			. "state: \"active\"\n"
			. "deleted_at: null\n"
			. "created_at: \"2026-03-06T00:00:00+00:00\"\n"
			. "updated_at: \"2026-03-06T00:00:00+00:00\"\n"
			. "snapshot_rev: 0\n"
			. 'remote_pad_id: "' . $value . "\"\n"
			. "---\n";
	}

	public function testGetSnapshotPartsFromBodyHandlesBodyWithoutMarkers(): void {
		$service = new PadFileService(new FixedClock());
		$parts = $service->getSnapshotPartsFromBody('raw text without sections');

		$this->assertSame('raw text without sections', $parts['text']);
		$this->assertSame('', $parts['html']);
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
		$this->assertSame(BindingService::STATE_ACTIVE, $restored->frontmatter['state']);
		$this->assertNull($restored->frontmatter['deleted_at']);
		$this->assertSame('new-pad', $restored->padId);
		$this->assertSame('https://pad.example.test/p/new-pad', $restored->padUrl);
		$this->assertSame(
			['text' => 'replaced text', 'html' => '<p>replaced html</p>'],
			$service->getSnapshotPartsFromBody($restored->body),
		);
	}

	public function testTheParsedDocumentCarriesTheSnapshotRevision(): void {
		$service = new PadFileService(new FixedClock());
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$this->assertSame(-1, $service->readPad($base)->snapshotRev);
		$this->assertSame(12, $service->readPad(str_replace('snapshot_rev: -1', 'snapshot_rev: 12', $base))->snapshotRev);
	}

	public function testANonNumericSnapshotRevisionIsAFormatError(): void {
		$service = new PadFileService(new FixedClock());
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$this->expectException(PadFileFormatException::class);
		$service->readPad(str_replace('snapshot_rev: -1', 'snapshot_rev: abc', $base));
	}

	// ------------------------------------------------------------------
	// Legacy ownpad + metadata helpers
	// ------------------------------------------------------------------

	public function testParseLegacyOwnpadShortcutExtractsUrlAndPadId(): void {
		$service = new PadFileService(new FixedClock());
		$parsed = $service->parseLegacyOwnpadShortcut(
			"[InternetShortcut]\nURL=https://pad.example.test/p/public-pad-id\n"
		);

		$this->assertNotNull($parsed);
		$this->assertSame('https://pad.example.test/p/public-pad-id', $parsed['url']);
		$this->assertSame('public-pad-id', $parsed['pad_id']);
	}

	public function testParseLegacyOwnpadShortcutReturnsNullForNonShortcutContent(): void {
		$service = new PadFileService(new FixedClock());
		$this->assertNull($service->parseLegacyOwnpadShortcut('not a shortcut'));
		$this->assertNull($service->parseLegacyOwnpadShortcut(''));
	}

	public function testParseLegacyOwnpadShortcutRejectsNonHttpUrls(): void {
		$service = new PadFileService(new FixedClock());
		$this->assertNull(
			$service->parseLegacyOwnpadShortcut("[InternetShortcut]\nURL=ftp://example.test/p/pad\n")
		);
	}

	public function testParseLegacyOwnpadShortcutKeepsLiteralPlusInPadId(): void {
		// `+` is a literal in URL path segments; only query/form encoding
		// treats it as a space. The previous urldecode() turned
		// `team+meeting` into `team meeting` and broke the binding lookup.
		$service = new PadFileService(new FixedClock());
		$parsed = $service->parseLegacyOwnpadShortcut(
			"[InternetShortcut]\nURL=https://pad.example.test/p/team+meeting\n"
		);
		$this->assertNotNull($parsed);
		$this->assertSame('team+meeting', $parsed['pad_id']);
	}

	public function testParseLegacyOwnpadShortcutDecodesPercentEncodedPlus(): void {
		// `%2B` (percent-encoded plus) must still decode to `+`.
		$service = new PadFileService(new FixedClock());
		$parsed = $service->parseLegacyOwnpadShortcut(
			"[InternetShortcut]\nURL=https://pad.example.test/p/team%2Bmeeting\n"
		);
		$this->assertNotNull($parsed);
		$this->assertSame('team+meeting', $parsed['pad_id']);
	}

	public function testParseLegacyOwnpadShortcutReturnsNullWithoutPadSegment(): void {
		$service = new PadFileService(new FixedClock());
		$this->assertNull(
			$service->parseLegacyOwnpadShortcut("[InternetShortcut]\nURL=https://example.test/somewhere/else\n")
		);
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

		// Missing remote_pad_id
		$this->assertFalse($service->isExternalFrontmatter([
			'pad_origin' => 'https://pad.remote.example',
		], 'ext.remote-id'));

		// Missing pad_origin
		$this->assertFalse($service->isExternalFrontmatter([
			'remote_pad_id' => 'remote-id',
		], 'ext.remote-id'));

		// Not an ext.* pad_id
		$this->assertFalse($service->isExternalFrontmatter([
			'pad_origin' => 'https://pad.remote.example',
			'remote_pad_id' => 'remote-id',
		], 'demo-pad'));
	}

	public function testExtractPadMetadataReturnsDefaultsForMissingFields(): void {
		$service = new PadFileService(new FixedClock());

		$this->assertSame(
			['pad_id' => '', 'access_mode' => '', 'pad_url' => '', 'alias_of_pad_id' => ''],
			$service->extractPadMetadata([])
		);
		$this->assertSame(
			[
				'pad_id' => 'pad-1',
				'access_mode' => 'public',
				'pad_url' => 'https://pad.example.test/p/pad-1',
				'alias_of_pad_id' => 'original-pad',
			],
			$service->extractPadMetadata([
				'pad_id' => 'pad-1',
				'access_mode' => 'public',
				'pad_url' => '  https://pad.example.test/p/pad-1  ',
				'alias_of_pad_id' => '  original-pad  ',
			])
		);
	}
}
