<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Service\RestoreService;
use OCA\EtherpadNextcloud\Service\SettleOutcome;
use PHPUnit\Framework\MockObject\MockObject;
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
use OCA\EtherpadNextcloud\Tests\Support\SettlesOnOpen;
use OCP\Constants;
use OCP\Files\File;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

class PublicPadContextServiceTest extends TestCase {
	use SettlesOnOpen;

	private function buildNoSleepLockRetryService(): PadFileLockRetryService {
		return new PadFileLockRetryService(static function (int $delay): void {
		});
	}

	public function testResolveBuildsPublicPadContextFromCachedShare(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		// Once: it travels on the result rather than being asked again.
		$file->expects($this->once())->method('getId')->willReturn(42);
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
			snapshotRev: -1,
		));

		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->once())
			->method('assertConsistentMapping')
			->with(42, 'g.group$pad', BindingService::ACCESS_PROTECTED);

		$openService = $this->createMock(PublicPadOpenService::class);
		$openService->expects($this->once())
			->method('open')
			->with($this->callback(static fn (ParsedPadFile $pad): bool => $pad->padId === 'g.group$pad' && $pad->accessMode === BindingService::ACCESS_PROTECTED && !$pad->isExternal), true, 'token')
			->willReturn(new PublicPadOpenTarget('', '', '', true));

		$urlGenerator = $this->createMock(IURLGenerator::class);
		// By id, so a rename between the two requests keeps the file.
		$urlGenerator->method('linkToRoute')
			->with('etherpad_nextcloud.publicViewer.padContent', ['token' => 'token', 'fileId' => 42])
			->willReturn('/public/content/token');

		$service = new PublicPadContextService(
			$shareResolver,
			$padFiles,
			$this->settleOnOpen($bindings),
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
			snapshotRev: -1,
		));

		$fetcher = $this->createMock(LivePadHtmlFetcher::class);
		$fetcher->expects($this->once())
			->method('fetchForPadFile')
			->with($this->anything(), 42)
			->willReturn(new LivePadHtml('<p>Now</p>', false));

		$service = new PublicPadContextService(
			$shareResolver,
			$padFiles,
			$this->settleOnOpen($this->createMock(BindingService::class)),
			$this->createMock(PublicPadOpenService::class),
			$fetcher,
			$this->buildNoSleepLockRetryService(),
			$this->createMock(IURLGenerator::class),
		);

		// No cached share: this call resolves the token itself.
		$this->assertSame('<p>Now</p>', $service->resolveContent('token', '')->html);
	}
	/**
	 * A public open decides a row that waits, as a signed-in one does, with
	 * the file the share reaches - the share's reader may be the only one
	 * who opens it - and opens once the row has taken its pad back.
	 */
	public function testAPublicOpenDecidesAWaitingRowWithTheSharedFile(): void {
		$file = $this->sharedFile();
		$bindings = $this->createMock(BindingService::class);
		$calls = 0;
		$bindings->expects($this->exactly(2))->method('assertConsistentMapping')->with(42, 'g.group$pad', BindingService::ACCESS_PROTECTED)
			->willReturnCallback(static function () use (&$calls): void {
				if (++$calls === 1) {
					throw new WaitingBindingException('Pad binding is not active.');
				}
			});
		$bindings->method('findByFileId')->willReturn(self::waitingRow(42, 'g.group$pad', BindingService::ACCESS_PROTECTED));
		$restores = $this->createMock(RestoreService::class);
		$restores->expects($this->once())->method('settleOpenedFile')->with($this->identicalTo($file))->willReturn(SettleOutcome::Settled);
		$openService = $this->createMock(PublicPadOpenService::class);
		$openService->expects($this->once())->method('open')->willReturn(new PublicPadOpenTarget('', '', '', true));

		$this->contextService($file, $bindings, $restores, $openService)->resolve('token', '', $this->shareOf($file));
	}

	/** A row that still waits once decided reaches the caller as it is, and nothing is opened. */
	public function testARowThatStillWaitsReachesTheCallerAsItIs(): void {
		$file = $this->sharedFile();
		$waiting = new WaitingBindingException('Pad binding is not active.');
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('assertConsistentMapping')->willThrowException($waiting);
		$bindings->method('findByFileId')->willReturn(self::waitingRow(42, 'g.group$pad', BindingService::ACCESS_PROTECTED));
		$restores = $this->createMock(RestoreService::class);
		$restores->expects($this->once())->method('settleOpenedFile')->willReturn(SettleOutcome::Unanswered);
		$openService = $this->createMock(PublicPadOpenService::class);
		$openService->expects($this->never())->method('open');

		$this->expectExceptionObject($waiting);

		$this->contextService($file, $bindings, $restores, $openService)->resolve('token', '', $this->shareOf($file));
	}

	private function sharedFile(): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('frontmatter');
		return $file;
	}

	private function shareOf(File $file): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getNode')->willReturn($file);
		return $share;
	}

	private function contextService(File $file, BindingService $bindings, RestoreService $restores, PublicPadOpenService $openService): PublicPadContextService {
		$padFiles = $this->createMock(PadFileService::class);
		$padFiles->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.group$pad', 'access_mode' => BindingService::ACCESS_PROTECTED],
			body: '',
			padId: 'g.group$pad',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));
		return new PublicPadContextService(
			new PublicShareResolver($this->createMock(IManager::class), new PathNormalizer()),
			$padFiles,
			$this->settleOnOpen($bindings, $restores),
			$openService,
			$this->createMock(LivePadHtmlFetcher::class),
			$this->buildNoSleepLockRetryService(),
			$this->createMock(IURLGenerator::class),
		);
	}
}
