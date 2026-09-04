<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\LivePadHtml;
use OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher;
use OCA\EtherpadNextcloud\Service\PadContentService;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

/**
 * What this service still owns is the file: resolve it for this user, read
 * it, hand the parsed frontmatter on. What may then be fetched is
 * `LivePadHtmlFetcher`'s question, and is tested there.
 */
class PadContentServiceTest extends TestCase {
	public function testTheResolvedFileDecidesWhichPadIsFetched(): void {
		$pad = $this->parsedPad();
		$fetcher = $this->createMock(LivePadHtmlFetcher::class);
		$fetcher->expects($this->once())
			->method('fetchForPadFile')
			->with($pad, 138)
			->willReturn(new LivePadHtml('<p>Now</p>', false));

		$this->assertSame('<p>Now</p>', $this->buildService($pad, $fetcher)->contentById('alice', 138)->html);
	}

	/**
	 * A sync holds the `.pad` file for a moment. Reading straight through
	 * would turn that moment into an error message and a "Try again" the
	 * reader has to press — the open path has used the retry for exactly
	 * this since long before the read-only view existed.
	 */
	public function testAShortFileLockIsWaitedOutRatherThanReported(): void {
		$fetcher = $this->createMock(LivePadHtmlFetcher::class);
		$fetcher->method('fetchForPadFile')->willReturn(new LivePadHtml('<p>Now</p>', false));

		$service = $this->buildService($this->parsedPad(), $fetcher, lockOnFirstRead: true);

		$this->assertSame('<p>Now</p>', $service->contentById('alice', 138)->html);
	}

	private function parsedPad(): ParsedPadFile {
		return new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.group$pad', 'access_mode' => BindingService::ACCESS_PROTECTED],
			body: '',
			padId: 'g.group$pad',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
		);
	}

	private function buildService(
		ParsedPadFile $pad,
		LivePadHtmlFetcher $livePadHtmlFetcher,
		bool $lockOnFirstRead = false,
	): PadContentService {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);
		if ($lockOnFirstRead) {
			$file->method('getContent')->willReturnOnConsecutiveCalls(
				$this->throwException(new LockedException('/Test.pad')),
				'frontmatter',
			);
		} else {
			$file->method('getContent')->willReturn('frontmatter');
		}

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($pad);

		return new PadContentService(
			$padFileService,
			$userNodeResolver,
			new PadFileLockRetryService(static function (int $delay): void {
			}),
			$livePadHtmlFetcher,
		);
	}
}
