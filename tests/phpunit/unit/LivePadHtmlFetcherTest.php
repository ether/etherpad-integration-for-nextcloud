<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingMismatchException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer;
use OCA\EtherpadNextcloud\Tests\Support\BuildsBoundPads;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use PHPUnit\Framework\TestCase;

class LivePadHtmlFetcherTest extends TestCase {
	use BuildsBoundPads;

	public function testOwnPadIsFetchedOverTheApiAndSanitized(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())
			->method('getHTMLForPreview')
			->with('g.group$pad')
			->willReturn('<h1 onclick="x()">Title</h1><p>Body</p><script>steal()</script>');

		$result = $this->buildFetcher($client)->fetchForPadFile($this->pad(), 138);

		$this->assertSame('<h1>Title</h1><p>Body</p>', $result->html);
		$this->assertFalse($result->isEmpty);
	}

	/** A row that still waits reaches the caller, and nothing is fetched. */
	public function testAWaitingBindingReachesTheCallerAsItIs(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('getHTMLForPreview');

		$this->expectException(WaitingBindingException::class);
		$this->buildFetcher($client, rows: [self::row('g.group$pad', BindingService::STATE_RESTORE_PENDING)])->fetchForPadFile($this->pad(), 138);
	}

	/**
	 * The check that stops a `.pad` file from pointing this app's API key
	 * at somebody else's pad. It runs on every content request, not only
	 * when the file was opened — the file is writable by whoever may write
	 * it, and the fetch is what does the reading. Neither a pad the row
	 * never named nor one without a row is read.
	 */
	public function testAPadNoRowGivesTheFileIsNotFetched(): void {
		foreach ([
			'another pad than the row\'s' => [[self::row('g.group$pad', replaced: 'g.group$before')], BindingMismatchException::class],
			'no row at all' => [[], MissingBindingException::class],
		] as $case => [$rows, $refusal]) {
			$client = $this->createMock(EtherpadClient::class);
			$client->expects($this->never())->method('getHTMLForPreview');
			try {
				$this->buildFetcher($client, rows: $rows)->fetchForPadFile($this->pad(padId: 'g.group$somebody-elses'), 138);
				$this->fail($case . ': fetched');
			} catch (\Exception $e) {
				$this->assertInstanceOf($refusal, $e, $case);
			}
		}
	}

	/** A file that still names the pad its row replaced shows the row's. */
	public function testAFileNamingThePadItsRowReplacedShowsTheRowsPad(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('getHTMLForPreview')->with('g.group$new')->willReturn('<p>Now</p>');

		$result = $this->buildFetcher($client, rows: [self::row('g.group$new', replaced: 'g.group$old')])
			->fetchForPadFile($this->pad(padId: 'g.group$old'), 138);

		$this->assertSame('<p>Now</p>', $result->html);
	}

	public function testForeignPadIsFetchedOverItsPublicExportWithoutABindingCheck(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->never())->method('findByFileId');

		$external = $this->createMock(ExternalPadExportFetcher::class);
		$external->expects($this->once())
			->method('fetchExternalPublicPadHtml')
			->with('https://remote.example/p/Test')
			->willReturn('<p>Remote</p><iframe src="x"></iframe>');

		$result = $this->buildFetcher(externalPadExportFetcher: $external, bindings: $bindings)
			->fetchForPadFile($this->externalPad(), 138);

		$this->assertSame('<p>Remote</p>', $result->html);
	}

	/** The rule for an external pad's metadata is ParsedPadFile::externalPadUrl()'s; the fetcher holds it. */
	public function testForeignPadWithoutAUrlIsRejected(): void {
		$external = $this->createMock(ExternalPadExportFetcher::class);
		$external->expects($this->never())->method('fetchExternalPublicPadHtml');

		// The link's problem, not Etherpad failing: its reason reaches the user.
		$this->expectException(ExternalPadException::class);
		$this->buildFetcher(externalPadExportFetcher: $external)
			->fetchForPadFile($this->externalPad(padUrl: ''), 138);
	}

	/**
	 * Etherpad answers an untouched pad with markup, not with nothing, so
	 * "empty" has to be decided on the text and not on the string length.
	 * A viewer that got this wrong would show a blank frame and no reason.
	 *
	 * @param string $html
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('emptyLookingExports')]
	public function testAPadWithNothingInItIsReportedAsEmpty(string $html): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getHTMLForPreview')->willReturn($html);

		$this->assertTrue($this->buildFetcher($client)->fetchForPadFile($this->pad(), 138)->isEmpty);
	}

	/** @return array<string,array{0:string}> */
	public static function emptyLookingExports(): array {
		return [
			'an untouched pad' => ['<br>'],
			'blank lines, padded with nbsp' => ['<p>&nbsp;</p><p> </p><br>'],
			// Markup the sanitizer strips down to nothing is empty as well.
			'markup that sanitizes away' => ['<script>alert(1)</script>'],
		];
	}

	private function pad(string $padId = 'g.group$pad'): ParsedPadFile {
		return new ParsedPadFile(
			frontmatter: ['pad_id' => $padId, 'access_mode' => BindingService::ACCESS_PROTECTED],
			body: '',
			padId: $padId,
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		);
	}

	private function externalPad(
		string $accessMode = BindingService::ACCESS_PUBLIC,
		string $padUrl = 'https://remote.example/p/Test',
	): ParsedPadFile {
		return new ParsedPadFile(
			frontmatter: ['pad_id' => 'ext.abc', 'access_mode' => $accessMode],
			body: '',
			padId: 'ext.abc',
			accessMode: $accessMode,
			padUrl: $padUrl,
			isExternal: true,
			snapshotRev: -1,
		);
	}

	/** File 138's row. */
	private static function row(string $padId, string $state = BindingService::STATE_ACTIVE, ?string $replaced = null): array {
		return ['file_id' => 138, 'pad_id' => $padId, 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => $state, 'deleted_at' => null, 'updated_at' => 100, 'replaced_pad_id' => $replaced];
	}

	/**
	 * Over $rows, or $bindings; unless a test says otherwise, file 138's row
	 * names the pad the file does.
	 *
	 * @param ?list<array<string,mixed>> $rows
	 */
	private function buildFetcher(
		?EtherpadClient $etherpadClient = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
		?array $rows = null,
		?BindingService $bindings = null,
	): LivePadHtmlFetcher {
		$bindings ??= new BindingService(new InMemoryBindingTable($rows ?? [self::row('g.group$pad')]), new FixedClock());
		return new LivePadHtmlFetcher(
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			new SnapshotHtmlSanitizer(),
			$this->boundPads($bindings),
		);
	}
}
