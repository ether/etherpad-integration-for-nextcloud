<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\LivePadHtml;
use OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher;
use OCA\EtherpadNextcloud\Service\PadContentService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class PadContentServiceTest extends TestCase {
	public function testOwnPadIsLoadedAfterTheBindingHolds(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'g.group$pad', BindingService::ACCESS_PROTECTED);

		$fetcher = $this->createMock(LivePadHtmlFetcher::class);
		$fetcher->expects($this->once())
			->method('fetchInternal')
			->with('g.group$pad')
			->willReturn(new LivePadHtml('<p>Now</p>', false));

		$result = $this->buildService($this->parsedPad(), $bindings, $fetcher)->contentById('alice', 138);

		$this->assertSame('<p>Now</p>', $result->html);
	}

	/**
	 * The check that stops a `.pad` file from pointing our API key at
	 * somebody else's pad. It has to run on every content request, not
	 * only when the file was opened — the file is writable by whoever may
	 * write it, and the fetch is what does the reading.
	 */
	public function testAPadIdThatDoesNotMatchTheBindingIsNotFetched(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('assertConsistentMapping')
			->willThrowException(new BindingException('pad_id does not match the stored binding.'));

		$fetcher = $this->createMock(LivePadHtmlFetcher::class);
		$fetcher->expects($this->never())->method('fetchInternal');
		$fetcher->expects($this->never())->method('fetchExternal');

		$this->expectException(BindingException::class);
		$this->buildService($this->parsedPad(padId: 'g.group$somebody-elses'), $bindings, $fetcher)->contentById('alice', 138);
	}

	public function testForeignPadIsLoadedFromItsOwnUrlWithoutABindingCheck(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->never())->method('assertConsistentMapping');

		$fetcher = $this->createMock(LivePadHtmlFetcher::class);
		$fetcher->expects($this->once())
			->method('fetchExternal')
			->with('https://remote.example/p/Test')
			->willReturn(new LivePadHtml('<p>Remote</p>', false));

		$pad = $this->parsedPad(
			padId: 'ext.abc',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://remote.example/p/Test',
			isExternal: true,
		);

		$this->assertSame('<p>Remote</p>', $this->buildService($pad, $bindings, $fetcher)->contentById('alice', 138)->html);
	}

	public function testForeignPadWithoutAUrlIsRejected(): void {
		$fetcher = $this->createMock(LivePadHtmlFetcher::class);
		$fetcher->expects($this->never())->method('fetchExternal');

		$pad = $this->parsedPad(padId: 'ext.abc', accessMode: BindingService::ACCESS_PUBLIC, isExternal: true);

		$this->expectException(EtherpadClientException::class);
		$this->buildService($pad, livePadHtmlFetcher: $fetcher)->contentById('alice', 138);
	}

	public function testForeignPadClaimingProtectedAccessIsRejected(): void {
		$fetcher = $this->createMock(LivePadHtmlFetcher::class);
		$fetcher->expects($this->never())->method('fetchExternal');

		$pad = $this->parsedPad(
			padId: 'ext.abc',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: 'https://remote.example/p/Test',
			isExternal: true,
		);

		$this->expectException(EtherpadClientException::class);
		$this->buildService($pad, livePadHtmlFetcher: $fetcher)->contentById('alice', 138);
	}

	private function parsedPad(
		string $padId = 'g.group$pad',
		string $accessMode = BindingService::ACCESS_PROTECTED,
		string $padUrl = '',
		bool $isExternal = false,
	): ParsedPadFile {
		return new ParsedPadFile(
			frontmatter: ['pad_id' => $padId, 'access_mode' => $accessMode],
			body: '',
			padId: $padId,
			accessMode: $accessMode,
			padUrl: $padUrl,
			isExternal: $isExternal,
		);
	}

	private function buildService(
		ParsedPadFile $pad,
		?BindingService $bindingService = null,
		?LivePadHtmlFetcher $livePadHtmlFetcher = null,
	): PadContentService {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($pad);

		return new PadContentService(
			$padFileService,
			$userNodeResolver,
			$bindingService ?? $this->createMock(BindingService::class),
			$livePadHtmlFetcher ?? $this->createMock(LivePadHtmlFetcher::class),
		);
	}
}
