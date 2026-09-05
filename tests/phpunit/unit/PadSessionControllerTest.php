<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PadControllerErrorMapper;
use OCA\EtherpadNextcloud\Controller\PadSessionController;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadContentService;
use OCA\EtherpadNextcloud\Service\PadInitializationService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadOpenService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadSessionControllerTest extends TestCase {
	public function testOpenByIdRejectsInvalidFileId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->openById(0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file ID.', $response->getData()['message']);
	}

	public function testOpenByIdRetriesLockedReadAndEventuallySucceeds(): void {
		$user = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'alice',
			'getDisplayName' => 'Alice',
		]);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$file = $this->buildPadFileNode();
		$file->expects($this->exactly(3))
			->method('getContent')
			->willReturnCallback(static function (): string {
				static $call = 0;
				$call++;
				if ($call < 3) {
					throw new LockedException('locked');
				}
				return 'frontmatter';
			});

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('readPad')
			->with('frontmatter')
			->willReturn(new ParsedPadFile(
				frontmatter: [
					'pad_id' => 'g.ABCDEFGHIJKLMNOP$pad-1',
					'access_mode' => BindingService::ACCESS_PUBLIC,
				],
				body: '',
				padId: 'g.ABCDEFGHIJKLMNOP$pad-1',
				accessMode: BindingService::ACCESS_PUBLIC,
				padUrl: '',
				isExternal: false,
				snapshotRev: -1,
			));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'g.ABCDEFGHIJKLMNOP$pad-1', BindingService::ACCESS_PUBLIC);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('buildPadUrl')
			->with('g.ABCDEFGHIJKLMNOP$pad-1')
			->willReturn('https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1');

		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->expects($this->once())
			->method('getSyncIntervalSeconds')
			->willReturn(30);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturnMap([
				['etherpad_nextcloud.padLifecycle.syncById', ['fileId' => 138], '/sync/138'],
				['etherpad_nextcloud.padLifecycle.syncStatusById', ['fileId' => 138], '/sync-status/138'],
				['etherpad_nextcloud.padSession.contentById', ['fileId' => 138], '/content/138'],
		]);
		$logger = $this->createMock(LoggerInterface::class);
		$padPaths = new PathNormalizer();
		// IRootFolder is a Folder, so the root doubles as the user's own
		// folder here: an id is resolved through getUserFolder(), which is
		// what registers the user's mounts before the lookup.
		$rootFolder->method('getUserFolder')->willReturn($rootFolder);
		$userNodeResolver = new UserNodeResolver($rootFolder, $this->createMock(LoggerInterface::class));
		$lockRetryService = $this->buildNoSleepLockRetryService();
		$padOpenService = new PadOpenService(
			$padFileService,
			$padPaths,
			$userNodeResolver,
			$lockRetryService,
			$bindingService,
			$etherpadClient,
			$this->createMock(ExternalPadExportFetcher::class),
			$this->createMock(PadSessionService::class),
			$logger,
		);
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $text);
		$padResponseService = new PadResponseService($urlGenerator, $appConfigService, $l10n);

		$controller = new PadSessionController(
			'etherpad_nextcloud',
			$this->createMock(IRequest::class),
			$userSession,
			$logger,
			$l10n,
			$padResponseService,
			new PadControllerErrorMapper($padResponseService, $logger),
			$padOpenService,
			$this->createMock(PadInitializationService::class),
			$this->createMock(PadMetadataService::class),
			$this->createMock(PadContentService::class),
		);

		$response = $controller->openById(138);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1', $response->getData()['url']);
	}

	public function testOpenByIdReturnsRetryableErrorWhenReadRemainsLocked(): void {
		$user = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'alice',
			'getDisplayName' => 'Alice',
		]);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$file = $this->buildPadFileNode();
		$file->expects($this->exactly(4))
			->method('getContent')
			->willThrowException(new LockedException('still locked'));

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$controller = $this->buildController(
			$this->createMock(IRequest::class),
			$userSession,
			rootFolder: $rootFolder,
		);
		$response = $controller->openById(138);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('Pad file is temporarily locked. Please retry.', $response->getData()['message']);
		$this->assertTrue($response->getData()['retryable']);
	}

	public function testOpenByIdReturnsExternalPadUrlForExternalPads(): void {
		$user = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'alice',
			'getDisplayName' => 'Alice',
		]);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$file = $this->buildPadFileNode();

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$frontmatter = [
			'pad_id' => 'ext.abc123',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.portal.fzs.de/p/Test',
			'pad_origin' => 'https://pad.portal.fzs.de',
			'remote_pad_id' => 'Test',
		];

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('readPad')
			->with('frontmatter')
			->willReturn(new ParsedPadFile(
				frontmatter: $frontmatter,
				body: '',
				padId: 'ext.abc123',
				accessMode: BindingService::ACCESS_PUBLIC,
				padUrl: 'https://pad.portal.fzs.de/p/Test',
				isExternal: true,
				snapshotRev: -1,
			));
		// The open no longer reads the stored copy at all — the preview
		// loads the pad from its own server over `content_url`.
		$padFileService->expects($this->never())->method('getSnapshotPartsFromBody');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('assertConsistentMapping');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('buildPadUrl');
		$externalPadExportFetcher = $this->createMock(ExternalPadExportFetcher::class);
		$externalPadExportFetcher->expects($this->once())
			->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://pad.portal.fzs.de/p/Test')
			->willReturn(['pad_url' => 'https://pad.portal.fzs.de/p/Test']);

		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->expects($this->once())
			->method('getSyncIntervalSeconds')
			->willReturn(30);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturnMap([
				['etherpad_nextcloud.padLifecycle.syncById', ['fileId' => 138], '/sync/138'],
				['etherpad_nextcloud.padLifecycle.syncStatusById', ['fileId' => 138], '/sync-status/138'],
				['etherpad_nextcloud.padSession.contentById', ['fileId' => 138], '/content/138'],
		]);
		$logger = $this->createMock(LoggerInterface::class);
		$padPaths = new PathNormalizer();
		$rootFolder->method('getUserFolder')->willReturn($rootFolder);
		$userNodeResolver = new UserNodeResolver($rootFolder, $this->createMock(LoggerInterface::class));
		$lockRetryService = $this->buildNoSleepLockRetryService();
		$padOpenService = new PadOpenService(
			$padFileService,
			$padPaths,
			$userNodeResolver,
			$lockRetryService,
			$bindingService,
			$etherpadClient,
			$externalPadExportFetcher,
			$this->createMock(PadSessionService::class),
			$logger,
		);
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $text);
		$padResponseService = new PadResponseService($urlGenerator, $appConfigService, $l10n);

		$controller = new PadSessionController(
			'etherpad_nextcloud',
			$this->createMock(IRequest::class),
			$userSession,
			$logger,
			$l10n,
			$padResponseService,
			new PadControllerErrorMapper($padResponseService, $logger),
			$padOpenService,
			$this->createMock(PadInitializationService::class),
			$this->createMock(PadMetadataService::class),
			$this->createMock(PadContentService::class),
		);

		$response = $controller->openById(138);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://pad.portal.fzs.de/p/Test', $response->getData()['url']);
		$this->assertTrue($response->getData()['is_external']);
		$this->assertSame('https://pad.portal.fzs.de/p/Test', $response->getData()['pad_url']);
		$this->assertSame('https://pad.portal.fzs.de/p/Test', $response->getData()['original_pad_url']);
		$this->assertSame('/content/138', $response->getData()['content_url'], 'the preview loads the pad itself');
	}

	public function testMetaByIdRejectsInvalidFileId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->metaById(0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file ID.', $response->getData()['message']);
	}

	public function testMetaByIdReturnsRetryableErrorWhenReadRemainsLocked(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$file = $this->buildPadFileNode();
		$file->expects($this->exactly(4))
			->method('getContent')
			->willThrowException(new LockedException('still locked'));

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$controller = $this->buildController(
			$this->createMock(IRequest::class),
			$userSession,
			rootFolder: $rootFolder,
		);
		$response = $controller->metaById(138);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('Pad file is temporarily locked. Please retry.', $response->getData()['message']);
		$this->assertTrue($response->getData()['retryable']);
	}

	public function testResolveByIdReturnsGenericServerErrorForUnexpectedFailures(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willThrowException(new \RuntimeException('Storage offline.'));

		$controller = $this->buildController(
			$this->createMock(IRequest::class),
			$userSession,
			rootFolder: $rootFolder,
		);
		$response = $controller->resolveById(138);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Could not resolve .pad file.', $response->getData()['message']);
	}

	private function buildController(
		IRequest $request,
		IUserSession $userSession,
		?IRootFolder $rootFolder = null,
		?PadFileService $padFileService = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
	): PadSessionController {
		$resolvedRootFolder = $rootFolder ?? $this->createMock(IRootFolder::class);
		$resolvedRootFolder->method('getUserFolder')->willReturn($resolvedRootFolder);
		$resolvedEtherpadClient = $etherpadClient ?? $this->createMock(EtherpadClient::class);
		$resolvedExternalPadExportFetcher = $externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class);
		$resolvedPadFileService = $padFileService ?? $this->createMock(PadFileService::class);
		$resolvedBindingService = $bindingService ?? $this->createMock(BindingService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$padPaths = new PathNormalizer();
		$userNodeResolver = new UserNodeResolver($resolvedRootFolder, $this->createMock(LoggerInterface::class));
		$lockRetryService = $this->buildNoSleepLockRetryService();
		$padMetadataService = new PadMetadataService($resolvedPadFileService, $padPaths, $userNodeResolver, $lockRetryService, $resolvedEtherpadClient, $resolvedExternalPadExportFetcher, $resolvedBindingService, $logger);
		$padOpenService = new PadOpenService(
			$resolvedPadFileService,
			$padPaths,
			$userNodeResolver,
			$lockRetryService,
			$resolvedBindingService,
			$resolvedEtherpadClient,
			$resolvedExternalPadExportFetcher,
			$this->createMock(PadSessionService::class),
			$logger,
		);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static function (string $route, array $params = []): string {
				if ($route === 'files.view.index') {
					return '/apps/files';
				}
				if ($route === 'etherpad_nextcloud.embed.showById') {
					return '/apps/etherpad_nextcloud/embed/by-id/' . ($params['fileId'] ?? '');
				}
				return '/' . $route;
			}
		);
		$appConfigService = $this->createMock(AppConfigService::class);
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $text);
		$padResponseService = new PadResponseService($urlGenerator, $appConfigService, $l10n);
		return new PadSessionController(
			'etherpad_nextcloud',
			$request,
			$userSession,
			$logger,
			$l10n,
			$padResponseService,
			new PadControllerErrorMapper($padResponseService, $logger),
			$padOpenService,
			$this->createMock(PadInitializationService::class),
			$padMetadataService,
			$this->createMock(PadContentService::class),
		);
	}

	private function buildPadFileNode(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);
		$file->method('getName')->willReturn('Test.pad');
		$file->method('getPath')->willReturn('/alice/files/Test.pad');
		$file->method('getContent')->willReturn('frontmatter');
		// Every test in this file is about an editable pad; the read-only
		// path is covered where the decision is made, in PadOpenServiceTest.
		$file->method('isUpdateable')->willReturn(true);
		return $file;
	}

	private function buildNoSleepLockRetryService(): PadFileLockRetryService {
		return new PadFileLockRetryService(static function (int $delay): void {
		});
	}
}
