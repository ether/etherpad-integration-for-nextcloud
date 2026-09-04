<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class LivePadHtmlFetcherTest extends TestCase {
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

	/**
	 * The check that stops a `.pad` file from pointing this app's API key
	 * at somebody else's pad. It runs on every content request, not only
	 * when the file was opened — the file is writable by whoever may write
	 * it, and the fetch is what does the reading.
	 */
	public function testAPadIdThatDoesNotMatchTheBindingIsNotFetched(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'g.group$somebody-elses', BindingService::ACCESS_PROTECTED)
			->willThrowException(new BindingException('pad_id does not match the stored binding.'));

		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('getHTMLForPreview');

		$this->expectException(BindingException::class);
		$this->buildFetcher($client, bindingService: $bindings)
			->fetchForPadFile($this->pad(padId: 'g.group$somebody-elses'), 138);
	}

	public function testForeignPadIsFetchedOverItsPublicExportWithoutABindingCheck(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->never())->method('assertConsistentMapping');

		$external = $this->createMock(ExternalPadExportFetcher::class);
		$external->expects($this->once())
			->method('fetchExternalPublicPadHtml')
			->with('https://remote.example/p/Test')
			->willReturn('<p>Remote</p><iframe src="x"></iframe>');

		$result = $this->buildFetcher(externalPadExportFetcher: $external, bindingService: $bindings)
			->fetchForPadFile($this->externalPad(), 138);

		$this->assertSame('<p>Remote</p>', $result->html);
	}

	public function testForeignPadWithoutAUrlIsRejected(): void {
		$external = $this->createMock(ExternalPadExportFetcher::class);
		$external->expects($this->never())->method('fetchExternalPublicPadHtml');

		$this->expectException(EtherpadClientException::class);
		$this->buildFetcher(externalPadExportFetcher: $external)
			->fetchForPadFile($this->externalPad(padUrl: ''), 138);
	}

	public function testForeignPadClaimingProtectedAccessIsRejected(): void {
		$external = $this->createMock(ExternalPadExportFetcher::class);
		$external->expects($this->never())->method('fetchExternalPublicPadHtml');

		$this->expectException(EtherpadClientException::class);
		$this->buildFetcher(externalPadExportFetcher: $external)
			->fetchForPadFile($this->externalPad(accessMode: BindingService::ACCESS_PROTECTED), 138);
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
		);
	}

	private function buildFetcher(
		?EtherpadClient $etherpadClient = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
		?BindingService $bindingService = null,
	): LivePadHtmlFetcher {
		return new LivePadHtmlFetcher(
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			new SnapshotHtmlSanitizer(),
			$bindingService ?? $this->createMock(BindingService::class),
		);
	}
}
