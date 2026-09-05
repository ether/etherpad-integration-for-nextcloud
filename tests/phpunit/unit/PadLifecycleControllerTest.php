<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PadControllerErrorMapper;
use OCA\EtherpadNextcloud\Controller\PadLifecycleController;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
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

class PadLifecycleControllerTest extends TestCase {
	public function testSyncByIdRejectsInvalidFileId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->syncById(-5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file ID.', $response->getData()['message']);
	}

	public function testSyncByIdRetriesLockedWriteAndEventuallySucceeds(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '0']);
		$file = $this->buildPadFileNode();
		$file->expects($this->exactly(3))
			->method('putContent')
			->with('updated-content')
			->willReturnCallback(static function (): void {
				static $call = 0;
				$call++;
				if ($call < 3) {
					throw new LockedException('locked');
				}
			});

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'g.ABCDEFGHIJKLMNOP$pad-1',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
			body: '',
			padId: 'g.ABCDEFGHIJKLMNOP$pad-1',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
			snapshotRev: 4,
		);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($parsedPad);
		$padFileService->method('withExportSnapshot')->with($this->identicalTo($parsedPad), new PadSnapshot('hello', '<p>hello</p>', 5))->willReturn('updated-content');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'g.ABCDEFGHIJKLMNOP$pad-1', BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->with('g.ABCDEFGHIJKLMNOP$pad-1')->willReturn(5);
		$etherpadClient->method('getText')->with('g.ABCDEFGHIJKLMNOP$pad-1')->willReturn('hello');
		$etherpadClient->method('getHTML')->with('g.ABCDEFGHIJKLMNOP$pad-1')->willReturn('<p>hello</p>');

		$controller = $this->buildController(
			$request,
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
		);
		$response = $controller->syncById(138);

		$this->assertSame(PadSyncService::STATUS_UPDATED, $response->getData()['status']);
		$this->assertFalse($response->getData()['external']);
		$this->assertSame(2, $response->getData()['lock_retries']);
		$this->assertSame(5, $response->getData()['snapshot_rev']);
	}

	public function testSyncByIdReturnsLockedWhenWriteRemainsLocked(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '1']);
		$file = $this->buildPadFileNode();
		$file->expects($this->exactly(4))
			->method('putContent')
			->with('updated-content')
			->willThrowException(new LockedException('still locked'));

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'g.ABCDEFGHIJKLMNOP$pad-1',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
			body: '',
			padId: 'g.ABCDEFGHIJKLMNOP$pad-1',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
			snapshotRev: 1,
		);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($parsedPad);
		$padFileService->method('withExportSnapshot')->with($this->identicalTo($parsedPad), new PadSnapshot('hello', '<p>hello</p>', 5))->willReturn('updated-content');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(5);
		$etherpadClient->method('getText')->willReturn('hello');
		$etherpadClient->method('getHTML')->willReturn('<p>hello</p>');

		$controller = $this->buildController(
			$request,
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
		);
		$response = $controller->syncById(138);

		$this->assertSame(PadSyncService::STATUS_LOCKED, $response->getData()['status']);
		$this->assertTrue($response->getData()['retryable']);
		$this->assertSame(3, $response->getData()['lock_retries']);
	}

	public function testSyncByIdForcedProtectedSyncDoesNotRewriteWhenRevisionIsUnchanged(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '1']);
		$file = $this->buildPadFileNode();
		$file->expects($this->never())->method('putContent');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'g.ABCDEFGHIJKLMNOP$pad-1',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
			body: '',
			padId: 'g.ABCDEFGHIJKLMNOP$pad-1',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
			snapshotRev: 5,
		);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($parsedPad);
		$padFileService->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn(['text' => 'hello', 'html' => '<p>hello</p>']);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('getRevisionsCount')
			->with('g.ABCDEFGHIJKLMNOP$pad-1')
			->willReturn(5);
		$etherpadClient->expects($this->once())->method('getText')->with('g.ABCDEFGHIJKLMNOP$pad-1')->willReturn('hello');
		$etherpadClient->expects($this->once())->method('getHTML')->with('g.ABCDEFGHIJKLMNOP$pad-1')->willReturn('<p>hello</p>');

		$controller = $this->buildController(
			$request,
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
		);
		$response = $controller->syncById(138);

		$this->assertSame(PadSyncService::STATUS_UNCHANGED, $response->getData()['status']);
		$this->assertFalse($response->getData()['external']);
		$this->assertTrue($response->getData()['forced']);
		$this->assertSame(5, $response->getData()['snapshot_rev']);
		$this->assertSame(5, $response->getData()['current_rev']);
	}

	public function testSyncByIdForcedExternalSyncDoesNotRewriteWhenTextIsUnchanged(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '1']);
		$file = $this->buildPadFileNode();
		$file->expects($this->never())->method('putContent');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'ext.remote-pad',
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'pad_url' => 'https://pad.example.test/p/public-pad',
				'remote_pad_id' => 'public-pad',
				'pad_origin' => 'https://pad.example.test',
			],
			body: '',
			padId: 'ext.remote-pad',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/public-pad',
			isExternal: true,
			snapshotRev: -1,
		);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($parsedPad);
		$padFileService->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn(['text' => 'same text', 'html' => '']);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping');

		$externalPadExportFetcher = $this->createMock(ExternalPadExportFetcher::class);
		$externalPadExportFetcher->expects($this->once())
			->method('normalizeAndFetchExternalPublicPadText')
			->with('https://pad.example.test/p/public-pad')
			->willReturn([
				'origin' => 'https://pad.example.test',
				'pad_id' => 'public-pad',
				'pad_url' => 'https://pad.example.test/p/public-pad',
				'text' => 'same text',
			]);

		$controller = $this->buildController(
			$request,
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
			externalPadExportFetcher: $externalPadExportFetcher,
		);
		$response = $controller->syncById(138);

		$this->assertSame(PadSyncService::STATUS_UNCHANGED, $response->getData()['status']);
		$this->assertTrue($response->getData()['external']);
		$this->assertTrue($response->getData()['forced']);
	}

	public function testFindOriginalByFileIdReturnsUnauthorizedWhenNoUserSession(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->findOriginalByFileId(7);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testFindOriginalByFileIdRejectsInvalidFileId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->findOriginalByFileId(0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testFindOriginalByFileIdSurfacesViewerUrlOnHit(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: ['pad_id' => 'orig'],
			body: '',
			padId: 'orig',
			accessMode: '',
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		);
		$padFileService->method('readPad')->with('doc')->willReturn($parsedPad);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')
			->with('orig', BindingService::STATE_ACTIVE)
			->willReturn(['file_id' => 42, 'pad_id' => 'orig']);

		$orphan = $this->createConfiguredMock(File::class, [
			'getId' => 700,
			'getName' => 'Copy.pad',
			'getContent' => 'doc',
		]);
		$orphan->method('getPath')->willReturn('/alice/files/Copy.pad');
		$original = $this->createConfiguredMock(File::class, [
			'getId' => 42,
			'getName' => 'Original.pad',
			'getContent' => 'doc',
			'getPath' => '/alice/files/Folder/Original.pad',
		]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->willReturnMap([
			[700, [$orphan]],
			[42, [$original]],
		]);

		$controller = $this->buildController(
			$this->createMock(IRequest::class),
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
		);
		$response = $controller->findOriginalByFileId(700);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['found']);
		$this->assertSame(42, $data['file_id']);
		$this->assertSame('/Folder/Original.pad', $data['path']);
		$this->assertNotEmpty($data['viewer_url']);
		$this->assertNotEmpty($data['embed_url']);
	}

	public function testFindOriginalByFileIdReturnsFoundFalseUniformlyOnMiss(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')->willReturn(null);

		// Orphan resolves, no binding found → uniform miss.
		$orphan = $this->createConfiguredMock(File::class, [
			'getId' => 800,
			'getName' => 'Copy.pad',
			'getContent' => 'doc',
			'getPath' => '/alice/files/Copy.pad',
		]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(800)->willReturn([$orphan]);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: ['pad_id' => 'orphan'],
			body: '',
			padId: 'orphan',
			accessMode: '',
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($parsedPad);

		$controller = $this->buildController(
			$this->createMock(IRequest::class),
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
		);
		$response = $controller->findOriginalByFileId(800);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// Critical: same payload shape as any other miss path.
		$this->assertSame(['found' => false], $response->getData());
	}

	public function testRecoverByFileIdReturnsUnauthorizedWhenNoUserSession(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->recoverByFileId(7);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testRecoverByFileIdRejectsInvalidFileId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->recoverByFileId(0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file ID.', $response->getData()['message']);
	}

	public function testRecoverByFileIdReturnsConflictWhenBindingAlreadyExists(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$lifecycleOps = $this->createMock(LifecycleService::class);
		$lifecycleOps->method('recoverByFileId')
			->willThrowException(new PadAlreadyHasBindingException('binding exists'));

		$controller = $this->buildController(
			$this->createMock(IRequest::class),
			$userSession,
			padLifecycleOperations: $lifecycleOps,
		);
		$response = $controller->recoverByFileId(42);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('This .pad file is already linked to a pad.', $response->getData()['message']);
	}

	public function testRecoverByFileIdSurfacesRestoredResult(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$lifecycleOps = $this->createMock(LifecycleService::class);
		$lifecycleOps->expects($this->once())
			->method('recoverByFileId')
			->with('alice', 99)
			->willReturn([
				'file_id' => 99,
				'status' => LifecycleService::RESULT_RESTORED,
				'old_pad_id' => 'orphan',
				'new_pad_id' => 'fresh',
			]);

		$controller = $this->buildController(
			$this->createMock(IRequest::class),
			$userSession,
			padLifecycleOperations: $lifecycleOps,
		);
		$response = $controller->recoverByFileId(99);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(LifecycleService::RESULT_RESTORED, $response->getData()['status']);
		$this->assertSame('fresh', $response->getData()['new_pad_id']);
	}

	private function buildController(
		IRequest $request,
		IUserSession $userSession,
		?IRootFolder $rootFolder = null,
		?PadFileService $padFileService = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
		?LifecycleService $padLifecycleOperations = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
	): PadLifecycleController {
		$resolvedRootFolder = $rootFolder ?? $this->createMock(IRootFolder::class);
		// IRootFolder is a Folder, so the root doubles as the user's own
		// folder here: an id is resolved through getUserFolder(), which is
		// what registers the user's mounts before the lookup.
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
		$padSyncService = new PadSyncService($resolvedPadFileService, $userNodeResolver, $lockRetryService, $resolvedBindingService, $resolvedEtherpadClient, $resolvedExternalPadExportFetcher, $logger);
		$padLifecycleOperations = $padLifecycleOperations
			?? $this->createMock(LifecycleService::class);
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
		return new PadLifecycleController(
			'etherpad_nextcloud',
			$request,
			$userSession,
			$logger,
			$l10n,
			$padResponseService,
			new PadControllerErrorMapper($padResponseService, $logger),
			$padLifecycleOperations,
			$padSyncService,
			$padMetadataService,
		);
	}

	private function buildPadFileNode(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);
		$file->method('getName')->willReturn('Test.pad');
		$file->method('getPath')->willReturn('/alice/files/Test.pad');
		$file->method('getContent')->willReturn('frontmatter');
		return $file;
	}

	private function buildNoSleepLockRetryService(): PadFileLockRetryService {
		return new PadFileLockRetryService(static function (int $delay): void {
		});
	}
}
