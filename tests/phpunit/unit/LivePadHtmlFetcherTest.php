<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher;
use OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class LivePadHtmlFetcherTest extends TestCase {
	public function testOwnPadIsFetchedOverTheApiAndSanitized(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())
			->method('getHTML')
			->with('g.group$pad')
			->willReturn('<h1 onclick="x()">Title</h1><p>Body</p><script>steal()</script>');

		$result = $this->buildFetcher($client)->fetchInternal('g.group$pad');

		$this->assertSame('<h1>Title</h1><p>Body</p>', $result->html);
		$this->assertFalse($result->isEmpty);
	}

	public function testForeignPadIsFetchedOverItsPublicExport(): void {
		$external = $this->createMock(ExternalPadExportFetcher::class);
		$external->expects($this->once())
			->method('normalizeAndFetchExternalPublicPadHtml')
			->with('https://remote.example/p/Test')
			->willReturn([
				'origin' => 'https://remote.example',
				'pad_id' => 'Test',
				'pad_url' => 'https://remote.example/p/Test',
				'html' => '<p>Remote</p><iframe src="x"></iframe>',
			]);

		$result = $this->buildFetcher(externalPadExportFetcher: $external)->fetchExternal('https://remote.example/p/Test');

		$this->assertSame('<p>Remote</p>', $result->html);
		$this->assertFalse($result->isEmpty);
	}

	/**
	 * Etherpad answers an untouched pad with markup, not with nothing, so
	 * "empty" has to be decided on the text and not on the string length.
	 * A viewer that got this wrong would show a blank frame and no reason.
	 */
	public function testAnUntouchedPadIsReportedAsEmpty(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getHTML')->willReturn('<br>');

		$this->assertTrue($this->buildFetcher($client)->fetchInternal('pad')->isEmpty);
	}

	public function testAPadOfBlankLinesIsReportedAsEmpty(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getHTML')->willReturn('<p>&nbsp;</p><p> </p><br>');

		$this->assertTrue($this->buildFetcher($client)->fetchInternal('pad')->isEmpty);
	}

	/** Markup the sanitizer strips down to nothing is empty as well. */
	public function testMarkupThatSanitizesToNothingIsReportedAsEmpty(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getHTML')->willReturn('<script>alert(1)</script>');

		$result = $this->buildFetcher($client)->fetchInternal('pad');

		$this->assertSame('', $result->html);
		$this->assertTrue($result->isEmpty);
	}

	private function buildFetcher(
		?EtherpadClient $etherpadClient = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
	): LivePadHtmlFetcher {
		return new LivePadHtmlFetcher(
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			new SnapshotHtmlSanitizer(),
		);
	}
}
