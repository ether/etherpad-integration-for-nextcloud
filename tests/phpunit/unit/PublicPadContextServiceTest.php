<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\LivePadHtml;
use OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\PublicPadContextService;
use OCA\EtherpadNextcloud\Service\PublicPadOpenService;
use OCA\EtherpadNextcloud\Service\PublicPadOpenTarget;
use OCA\EtherpadNextcloud\Service\PublicShareResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Constants;
use OCP\Files\File;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

class PublicPadContextServiceTest extends TestCase {
	private function buildNoSleepLockRetryService(): PadFileLockRetryService {
		return new PadFileLockRetryService(static function (int $delay): void {
		});
	}

	public function testResolveBuildsPublicPadContextFromCachedShare(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('frontmatter');

		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getNode')->willReturn($file);

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->never())->method('getShareByToken');
		$shareResolver = new PublicShareResolver($shareManager, new PathNormalizer());

		$frontmatter = [
			'pad_id' => 'g.group$pad',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'pad_url' => '',
		];
		$padFiles = $this->createMock(PadFileService::class);
		$padFiles->expects($this->once())->method('readPad')->with('frontmatter')->willReturn(new ParsedPadFile(
			frontmatter: $frontmatter,
			body: '',
			padId: 'g.group$pad',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
		));

		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->once())
			->method('assertConsistentMapping')
			->with(42, 'g.group$pad', BindingService::ACCESS_PROTECTED);

		$openService = $this->createMock(PublicPadOpenService::class);
		$openService->expects($this->once())
			->method('open')
			->with('g.group$pad', BindingService::ACCESS_PROTECTED, true, 'token', false, '')
			->willReturn(new PublicPadOpenTarget('', '', '', true));

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->with('etherpad_nextcloud.publicViewer.padContent', ['token' => 'token'])
			->willReturn('/public/content/token');

		$service = new PublicPadContextService(
			$shareResolver,
			$padFiles,
			$bindings,
			$openService,
			$this->createMock(LivePadHtmlFetcher::class),
			$this->buildNoSleepLockRetryService(),
			$urlGenerator,
		);
		$context = $service->resolve('token', '', $share);

		$this->assertSame('Shared.pad', $context->title);
		$this->assertSame('', $context->url);
		$this->assertFalse($context->isExternal);
		$this->assertTrue($context->isReadOnlyView);
		$this->assertSame('/public/content/token', $context->contentUrl, 'the viewer loads the pad itself');
	}

	/**
	 * The retry goes through the share again — token, password gate and the
	 * file's membership in the share all have to hold at the moment of the
	 * fetch, not merely when the page was opened. What the resolved file is
	 * then allowed to point at is `LivePadHtmlFetcher`'s question.
	 */
	public function testResolveContentResolvesTheShareAgainBeforeFetching(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('frontmatter');

		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getNode')->willReturn($file);

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())->method('getShareByToken')->with('token')->willReturn($share);
		$shareResolver = new PublicShareResolver($shareManager, new PathNormalizer());

		$padFiles = $this->createMock(PadFileService::class);
		$padFiles->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.group$pad', 'access_mode' => BindingService::ACCESS_PROTECTED],
			body: '',
			padId: 'g.group$pad',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
		));

		$fetcher = $this->createMock(LivePadHtmlFetcher::class);
		$fetcher->expects($this->once())
			->method('fetchForPadFile')
			->with($this->anything(), 42)
			->willReturn(new LivePadHtml('<p>Now</p>', false));

		$service = new PublicPadContextService(
			$shareResolver,
			$padFiles,
			$this->createMock(BindingService::class),
			$this->createMock(PublicPadOpenService::class),
			$fetcher,
			$this->buildNoSleepLockRetryService(),
			$this->createMock(IURLGenerator::class),
		);

		// No cached share: this call resolves the token itself.
		$this->assertSame('<p>Now</p>', $service->resolveContent('token', '')->html);
	}
}
