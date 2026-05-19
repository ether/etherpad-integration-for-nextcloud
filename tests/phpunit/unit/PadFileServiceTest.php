<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use PHPUnit\Framework\TestCase;

class PadFileServiceTest extends TestCase {
	// ------------------------------------------------------------------
	// Parse + serialize roundtrip happy paths
	// ------------------------------------------------------------------

	public function testBuildInitialDocumentRoundtripsAllRequiredFields(): void {
		$service = new PadFileService();
		$document = $service->buildInitialDocument(
			42,
			'g.abc$demo',
			BindingService::ACCESS_PROTECTED,
			'snapshot body'
		);

		$parsed = $service->parsePadFile($document);

		$this->assertSame(PadFileService::FORMAT_V1, $parsed['frontmatter']['format']);
		$this->assertSame(42, $parsed['frontmatter']['file_id']);
		$this->assertSame('g.abc$demo', $parsed['frontmatter']['pad_id']);
		$this->assertSame(BindingService::ACCESS_PROTECTED, $parsed['frontmatter']['access_mode']);
		$this->assertSame(BindingService::STATE_ACTIVE, $parsed['frontmatter']['state']);
		$this->assertNull($parsed['frontmatter']['deleted_at']);
		$this->assertSame(-1, $parsed['frontmatter']['snapshot_rev']);
		$this->assertSame('snapshot body', $parsed['body']);
	}

	public function testParsePadFileNormalizesCrlfLineEndings(): void {
		$service = new PadFileService();
		$document = str_replace(
			"\n",
			"\r\n",
			$service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC, 'body')
		);

		$parsed = $service->parsePadFile($document);

		$this->assertSame('demo-pad', $parsed['frontmatter']['pad_id']);
		$this->assertSame('body', $parsed['body']);
	}

	public function testSerializeIncludesOptionalPadUrlOnlyWhenSet(): void {
		$service = new PadFileService();
		$withPadUrl = $service->buildInitialDocument(
			10,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			'',
			'https://pad.example.test/p/demo-pad'
		);
		$withoutPadUrl = $service->buildInitialDocument(11, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$this->assertStringContainsString('pad_url: "https://pad.example.test/p/demo-pad"', $withPadUrl);
		$this->assertStringNotContainsString('pad_url:', $withoutPadUrl);
	}

	public function testQuotedStringScalarsRoundtripWithEscapes(): void {
		$service = new PadFileService();
		$padUrl = 'https://pad.example.org/p/say-"hello"-path\\with\\slashes';
		$document = $service->buildInitialDocument(
			51,
			'demo-pad',
			BindingService::ACCESS_PUBLIC,
			'',
			$padUrl
		);

		$parsed = $service->parsePadFile($document);

		$this->assertSame($padUrl, (string)$parsed['frontmatter']['pad_url']);
	}

	public function testBuildInitialDocumentPersistsExtraFrontmatterForExternalPads(): void {
		$service = new PadFileService();
		$document = $service->buildInitialDocument(
			7,
			'ext.remote-pad-id',
			BindingService::ACCESS_PUBLIC,
			'',
			'https://pad.remote.example/p/remote-pad-id',
			[
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

		(new PadFileService())->parsePadFile("not-a-frontmatter");
	}

	public function testParsePadFileRejectsUnsupportedFormat(): void {
		$content = "---\nformat: \"etherpad-nextcloud/0\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Unsupported .pad format');

		(new PadFileService())->parsePadFile($content);
	}

	public function testParsePadFileRejectsMissingRequiredKey(): void {
		// Missing snapshot_rev
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Missing required frontmatter key: snapshot_rev');

		(new PadFileService())->parsePadFile($content);
	}

	public function testParsePadFileRejectsInvalidSnapshotRevBelowMinusOne(): void {
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: -2\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid snapshot_rev');

		(new PadFileService())->parsePadFile($content);
	}

	public function testParsePadFileRejectsNonHttpPadUrl(): void {
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\npad_url: \"ftp://example.org/p/demo\"\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid pad_url');

		(new PadFileService())->parsePadFile($content);
	}

	public function testParsePadFileRejectsInvalidAccessMode(): void {
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"private\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid access_mode');

		(new PadFileService())->parsePadFile($content);
	}

	public function testParsePadFileRejectsMalformedYamlLine(): void {
		$content = "---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"public\"\nstate: \"active\"\n- broken\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\n---\n";

		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Invalid YAML frontmatter');

		(new PadFileService())->parsePadFile($content);
	}

	public function testParsePadFileAcceptsLegacyStateValuesForBackwardCompat(): void {
		// Old plugin versions wrote "trashed" / "purged" before the lifecycle
		// simplification. Existing files in users' trashbins must still parse.
		$service = new PadFileService();
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
		$service = new PadFileService();
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$updated = $service->withExportSnapshot($base, "line-a\nline-b", '<p>line-a</p>', 7);

		$parsed = $service->parsePadFile($updated);
		$this->assertSame(7, $parsed['frontmatter']['snapshot_rev']);
		$this->assertSame("line-a\nline-b", $service->getTextSnapshotForRestore($updated));
		$this->assertSame('<p>line-a</p>', $service->getHtmlSnapshotForRestore($updated));
	}

	public function testWithExportSnapshotEmptyValuesOverwritePreviousSnapshot(): void {
		// Important product decision: there are no archive semantics in the
		// snapshot. If Etherpad content is empty, the .pad snapshot must also
		// become empty (otherwise stale content would resurface on restore).
		$service = new PadFileService();
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);
		$withContent = $service->withExportSnapshot($base, 'previous text', '<p>previous html</p>', 5);

		$cleared = $service->withExportSnapshot($withContent, '', '', 6);

		$this->assertSame('', $service->getTextSnapshotForRestore($cleared));
		$this->assertSame('', $service->getHtmlSnapshotForRestore($cleared));
		$this->assertSame(6, $service->getSnapshotRevision($cleared));
	}

	public function testWithExportSnapshotOmitsHtmlSectionWhenRequested(): void {
		$service = new PadFileService();
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);

		$textOnly = $service->withExportSnapshot($base, 'just text', '<p>ignored</p>', 1, false);

		$this->assertStringNotContainsString('[HTML-BEGIN]', $textOnly);
		$this->assertStringNotContainsString('[HTML-END]', $textOnly);
		$this->assertSame('just text', $service->getTextSnapshotForRestore($textOnly));
		$this->assertSame('', $service->getHtmlSnapshotForRestore($textOnly));
	}

	public function testGetSnapshotPartsFromBodyHandlesBodyWithoutMarkers(): void {
		$service = new PadFileService();
		$parts = $service->getSnapshotPartsFromBody('raw text without sections');

		$this->assertSame('raw text without sections', $parts['text']);
		$this->assertSame('', $parts['html']);
	}

	public function testWithStateAndSnapshotPreservesExistingHtmlBody(): void {
		// withStateAndSnapshot writes a new text snapshot but should keep the
		// HTML body part already on disk. Used by trash flow when only fresh
		// text is available.
		$service = new PadFileService();
		$base = $service->buildInitialDocument(1, 'demo-pad', BindingService::ACCESS_PUBLIC);
		$withExport = $service->withExportSnapshot($base, 'original text', '<p>kept html</p>', 4);

		$updated = $service->withStateAndSnapshot(
			$withExport,
			BindingService::STATE_ACTIVE,
			'replaced text',
			null,
			1700000000
		);

		$this->assertSame('replaced text', $service->getTextSnapshotForRestore($updated));
		$this->assertSame('<p>kept html</p>', $service->getHtmlSnapshotForRestore($updated));
		$parsed = $service->parsePadFile($updated);
		$this->assertSame('2023-11-14T22:13:20+00:00', (string)$parsed['frontmatter']['deleted_at']);
	}

	public function testGetSnapshotRevisionReturnsMinusOneForNonNumericValue(): void {
		$service = new PadFileService();
		$this->assertSame(-1, $service->getSnapshotRevisionFromFrontmatter([]));
		$this->assertSame(-1, $service->getSnapshotRevisionFromFrontmatter(['snapshot_rev' => 'abc']));
		$this->assertSame(12, $service->getSnapshotRevisionFromFrontmatter(['snapshot_rev' => 12]));
	}

	// ------------------------------------------------------------------
	// Legacy ownpad + metadata helpers
	// ------------------------------------------------------------------

	public function testParseLegacyOwnpadShortcutExtractsUrlAndPadId(): void {
		$service = new PadFileService();
		$parsed = $service->parseLegacyOwnpadShortcut(
			"[InternetShortcut]\nURL=https://pad.example.test/p/public-pad-id\n"
		);

		$this->assertNotNull($parsed);
		$this->assertSame('https://pad.example.test/p/public-pad-id', $parsed['url']);
		$this->assertSame('public-pad-id', $parsed['pad_id']);
	}

	public function testParseLegacyOwnpadShortcutReturnsNullForNonShortcutContent(): void {
		$service = new PadFileService();
		$this->assertNull($service->parseLegacyOwnpadShortcut('not a shortcut'));
		$this->assertNull($service->parseLegacyOwnpadShortcut(''));
	}

	public function testParseLegacyOwnpadShortcutRejectsNonHttpUrls(): void {
		$service = new PadFileService();
		$this->assertNull(
			$service->parseLegacyOwnpadShortcut("[InternetShortcut]\nURL=ftp://example.test/p/pad\n")
		);
	}

	public function testParseLegacyOwnpadShortcutKeepsLiteralPlusInPadId(): void {
		// `+` is a literal in URL path segments; only query/form encoding
		// treats it as a space. The previous urldecode() turned
		// `team+meeting` into `team meeting` and broke the binding lookup.
		$service = new PadFileService();
		$parsed = $service->parseLegacyOwnpadShortcut(
			"[InternetShortcut]\nURL=https://pad.example.test/p/team+meeting\n"
		);
		$this->assertNotNull($parsed);
		$this->assertSame('team+meeting', $parsed['pad_id']);
	}

	public function testParseLegacyOwnpadShortcutDecodesPercentEncodedPlus(): void {
		// `%2B` (percent-encoded plus) must still decode to `+`.
		$service = new PadFileService();
		$parsed = $service->parseLegacyOwnpadShortcut(
			"[InternetShortcut]\nURL=https://pad.example.test/p/team%2Bmeeting\n"
		);
		$this->assertNotNull($parsed);
		$this->assertSame('team+meeting', $parsed['pad_id']);
	}

	public function testParseLegacyOwnpadShortcutReturnsNullWithoutPadSegment(): void {
		$service = new PadFileService();
		$this->assertNull(
			$service->parseLegacyOwnpadShortcut("[InternetShortcut]\nURL=https://example.test/somewhere/else\n")
		);
	}

	public function testInferAccessModeFromPadIdMapsGroupPadsToProtected(): void {
		$service = new PadFileService();

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
		$service = new PadFileService();

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
		$service = new PadFileService();

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
