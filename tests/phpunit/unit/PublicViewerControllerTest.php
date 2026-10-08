<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PublicViewerController;
use OCA\EtherpadNextcloud\Controller\PublicViewerControllerErrorMapper;
use OCA\EtherpadNextcloud\Http\CookieHeaders;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PublicPadContextService;
use OCA\EtherpadNextcloud\Service\PublicPadOpenService;
use OCA\EtherpadNextcloud\Service\PublicShareResolver;
use OCA\EtherpadNextcloud\Tests\Support\RecordingCookieHeaders;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http;
use OCP\Constants;
use OCP\Files\File;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PublicViewerControllerTest extends TestCase {
	use \OCA\EtherpadNextcloud\Tests\Support\BuildsErrorMappers;


	public function testProtectedReadOnlyPublicShareReturnsSnapshotWithoutEtherpadSessionCookie(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('frontmatter');

		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($file);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$frontmatter = [
			'pad_id' => 'g.abcdefghijklmnop$Shared',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'pad_url' => '',
		];

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('readPad')
			->with('frontmatter')
			->willReturn(new ParsedPadFile(
				frontmatter: $frontmatter,
				body: '',
				padId: 'g.abcdefghijklmnop$Shared',
				accessMode: BindingService::ACCESS_PROTECTED,
				padUrl: '',
				isExternal: false,
				snapshotRev: -1,
			));
		// The stored copy plays no part in opening any more: the viewer
		// loads the pad from the pad server over `content_url`.
		$padFileService->expects($this->never())->method('getSnapshotPartsFromBody');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(42, 'g.abcdefghijklmnop$Shared', BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('getReadOnlyPadUrl');
		$etherpadClient->expects($this->never())->method('buildPadUrl');

		$padSessionService = $this->createMock(PadSessionService::class);
		$padSessionService->expects($this->never())->method('createProtectedOpenContext');
		$padSessionService->expects($this->never())->method('buildSetCookieHeader');

		$cookies = new RecordingCookieHeaders();

		$response = $this->buildController(
			$shareManager,
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
			padSessionService: $padSessionService,
			cookies: $cookies,
		)->openPadData('share-token');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('', $response->getData()['url']);
		$this->assertTrue($response->getData()['is_readonly_view']);
		$this->assertSame('/public/content/share-token', $response->getData()['content_url']);
		$this->assertSame([], $cookies->sent);
		$this->assertArrayNotHasKey('Set-Cookie', $response->getHeaders());
	}

	/**
	 * A share that may write opens a protected pad with a session of its
	 * own: the pad's address in the answer, and the cookie that carries
	 * the session on it. Without the header the recipient gets an address
	 * Etherpad refuses.
	 */
	public function testAShareThatMayWriteGetsTheSessionCookieWithThePadsAddress(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('frontmatter');
		$file->method('isUpdateable')->willReturn(true);

		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($file);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		$shareManager = $this->createMock(IManager::class);
		$shareManager->method('getShareByToken')->with('share-token')->willReturn($share);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: [],
			body: '',
			padId: 'g.abcdefghijklmnop$Shared',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));

		$cookie = ['name' => 'sessionID', 'value' => 's.abc'];
		$padSessionService = $this->createMock(PadSessionService::class);
		$padSessionService->expects($this->once())
			->method('createProtectedOpenContext')
			->with('public-share:share-token', $this->anything(), 'g.abcdefghijklmnop$Shared', $this->anything())
			->willReturn(['url' => 'https://pad.example.test/p/g.abcdefghijklmnop$Shared', 'cookie' => $cookie]);
		$padSessionService->expects($this->once())
			->method('buildSetCookieHeader')
			->with($cookie)
			->willReturn('sessionID=s.abc; Path=/; Secure');

		$cookies = new RecordingCookieHeaders();

		$response = $this->buildController(
			$shareManager,
			padFileService: $padFileService,
			padSessionService: $padSessionService,
			cookies: $cookies,
		)->openPadData('share-token');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://pad.example.test/p/g.abcdefghijklmnop$Shared', $response->getData()['url']);
		$this->assertFalse($response->getData()['is_readonly_view']);
		// Beside the session cookie Nextcloud sends the visitor in the same
		// answer, not as one of the response's headers, which would replace it.
		$this->assertSame([['Set-Cookie: sessionID=s.abc; Path=/; Secure', false]], $cookies->sent);
		$this->assertArrayNotHasKey('Set-Cookie', $response->getHeaders());
	}

	public function testPublicExternalPadShareReturnsNormalizedUrlAndAContentUrl(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('External.pad');
		$file->method('getId')->willReturn(77);
		$file->method('getContent')->willReturn('frontmatter');

		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($file);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$frontmatter = [
			'pad_id' => 'ext.abc123',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.portal.example/p/Test',
			'pad_origin' => 'https://pad.portal.example',
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
				padUrl: 'https://pad.portal.example/p/Test',
				isExternal: true,
				snapshotRev: -1,
			));
		$padFileService->expects($this->never())->method('getSnapshotPartsFromBody');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('assertConsistentMapping');

		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->expects($this->once())
			->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://pad.portal.example/p/Test')
			->willReturn(['pad_url' => 'https://pad.portal.example/p/Test']);

		$padSessionService = $this->createMock(PadSessionService::class);
		$padSessionService->expects($this->never())->method('createProtectedOpenContext');
		$padSessionService->expects($this->never())->method('buildSetCookieHeader');

		$cookies = new RecordingCookieHeaders();

		$response = $this->buildController(
			$shareManager,
			padFileService: $padFileService,
			bindingService: $bindingService,
			externalPadExportFetcher: $fetcher,
			padSessionService: $padSessionService,
			cookies: $cookies,
		)->openPadData('share-token');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://pad.portal.example/p/Test', $response->getData()['url']);
		$this->assertSame('https://pad.portal.example/p/Test', $response->getData()['original_pad_url']);
		$this->assertTrue($response->getData()['is_external']);
		$this->assertFalse($response->getData()['is_readonly_view']);
		$this->assertSame('/public/content/share-token', $response->getData()['content_url'], 'the preview loads the pad itself');
		$this->assertSame([], $cookies->sent);
		$this->assertArrayNotHasKey('Set-Cookie', $response->getHeaders());
	}

	public function testOpenPadDataRejectsExternalProtectedMetadata(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('frontmatter');

		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($file);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('readPad')
			->with('frontmatter')
			->willReturn(new ParsedPadFile(
				frontmatter: [
					'pad_id' => 'ext.123',
					'access_mode' => BindingService::ACCESS_PROTECTED,
					'pad_url' => 'https://remote.example.test/p/demo',
					'pad_origin' => 'https://remote.example.test',
					'remote_pad_id' => 'demo',
				],
				body: '',
				padId: 'ext.123',
				accessMode: BindingService::ACCESS_PROTECTED,
				padUrl: 'https://remote.example.test/p/demo',
				isExternal: true,
				snapshotRev: -1,
			));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('assertConsistentMapping');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->expects($this->never())->method('normalizeAndValidateExternalPublicPadUrl');

		$padSessionService = $this->createMock(PadSessionService::class);
		$padSessionService->expects($this->never())->method('createProtectedOpenContext');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getWebroot')->willReturn('');
		$urlGenerator->method('linkToRoute')->willReturn('/public/content/share-token');
		$shareResolver = new PublicShareResolver($shareManager, new PathNormalizer());
		$publicPadOpenService = new PublicPadOpenService($etherpadClient, $this->createMock(ManagedPadLifecycle::class), $fetcher, $padSessionService);

		$controller = new PublicViewerController(
			'etherpad_nextcloud',
			$this->createMock(IRequest::class),
			$shareResolver,
			new PublicPadContextService(
				$shareResolver,
				$padFileService,
				$bindingService,
				$publicPadOpenService,
				$this->createMock(LivePadHtmlFetcher::class),
				new PadFileLockRetryService(static function (int $delay): void {
				}),
				$urlGenerator,
			),
			$this->buildPadResponseService($urlGenerator),
			$this->publicErrorMapper($this->untranslated()),
			$this->createMock(ISession::class),
			$cookies = new RecordingCookieHeaders(),
		);

		$response = $controller->openPadData('share-token');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $cookies->sent);
		// The link's problem, not Etherpad down: trying again would not help.
		$this->assertSame('The pad this file links to on another server could not be read.', $response->getData()['message']);
	}

	public function testPasswordProtectedShareRequiresAuthenticatedSession(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPassword')->willReturn('stored-password-hash');

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$session = $this->createMock(ISession::class);
		$session->method('get')->with('public_link_authenticated_frontend')->willReturn('[]');

		$controller = $this->buildController($shareManager, session: $session);
		$controller->setToken('share-token');

		$this->assertTrue($controller->isValidToken());
		$this->assertFalse($controller->isAuthenticated());
	}

	public function testPasswordProtectedShareAcceptsMatchingPublicShareSession(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPassword')->willReturn('stored-password-hash');

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$session = $this->createMock(ISession::class);
		$session->method('get')
			->with('public_link_authenticated_frontend')
			->willReturn('{"share-token":"stored-password-hash"}');

		$controller = $this->buildController($shareManager, session: $session);
		$controller->setToken('share-token');

		$this->assertTrue($controller->isValidToken());
		$this->assertTrue($controller->isAuthenticated());
	}

	public function testOpenPadDataRejectsShareWithoutReadPermission(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_UPDATE);
		$share->expects($this->never())->method('getNode');

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$response = $this->buildController($shareManager)->openPadData('share-token');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('This share link does not allow reading files.', $response->getData()['message']);
	}

	/**
	 * A public request's log line names its file by the id it gave, never by
	 * its path: that may be a DAV URL carrying the share token.
	 */
	public function testALogLineNamesTheFileByIdAndNeverByPath(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_UPDATE);
		$shareManager = $this->createMock(IManager::class);
		$shareManager->method('getShareByToken')->willReturn($share);
		$path = 'https://nc.example/public.php/dav/files/share-token/Notes.pad';
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['fileId', null, '7'], ['file', null, $path]]);
		$seen = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(static function (string $message, array $context) use (&$seen): void {
			$seen[] = $context;
		});

		$this->buildController($shareManager, request: $request, logger: $logger)->openPadData('share-token', $path, '7');

		$this->assertCount(1, $seen);
		$this->assertSame(7, $seen[0]['fileId']);
		$this->assertStringNotContainsString('share-token', json_encode($seen[0], JSON_THROW_ON_ERROR));
	}

	/**
	 * The two routes anyone holding a link can call: the content makes this
	 * server fetch a pad, the open of a writable link to a protected pad
	 * starts an Etherpad session that lives for hours. The open is how
	 * every visitor gets in, a class behind one address at once, so it
	 * allows more.
	 */
	public function testTheAnonymousRoutesAreThrottled(): void {
		// Signed in, Nextcloud counts by address too unless a method says
		// otherwise: both count each user on their own.
		foreach (['openPadData' => 300, 'padContent' => 60] as $method => $limit) {
			foreach ([\OCP\AppFramework\Http\Attribute\AnonRateLimit::class, \OCP\AppFramework\Http\Attribute\UserRateLimit::class] as $kind) {
				$limits = (new \ReflectionMethod(PublicViewerController::class, $method))->getAttributes($kind);
				$this->assertCount(1, $limits, $method . ' ' . $kind);
				$this->assertSame(['limit' => $limit, 'period' => 60], $limits[0]->getArguments(), $method . ' ' . $kind);
			}
		}
	}

	private function buildController(
		IManager $shareManager,
		?PadFileService $padFileService = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
		?PadSessionService $padSessionService = null,
		?ISession $session = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
		?IRequest $request = null,
		?LoggerInterface $logger = null,
		?CookieHeaders $cookies = null,
	): PublicViewerController {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getWebroot')->willReturn('');
		$urlGenerator->method('linkToRoute')->willReturn('/public/content/share-token');
		$padFileService ??= $this->createMock(PadFileService::class);
		$etherpadClient ??= $this->createMock(EtherpadClient::class);
		$externalPadExportFetcher ??= $this->createMock(ExternalPadExportFetcher::class);
		$padSessionService ??= $this->createMock(PadSessionService::class);
		$bindingService ??= $this->createMock(BindingService::class);
		$shareResolver = new PublicShareResolver($shareManager, new PathNormalizer());
		$publicPadOpenService = new PublicPadOpenService($etherpadClient, $this->createMock(ManagedPadLifecycle::class), $externalPadExportFetcher, $padSessionService);

		return new PublicViewerController(
			'etherpad_nextcloud',
			$request ?? $this->createMock(IRequest::class),
			$shareResolver,
			new PublicPadContextService(
				$shareResolver,
				$padFileService,
				$bindingService,
				$publicPadOpenService,
				$this->createMock(LivePadHtmlFetcher::class),
				new PadFileLockRetryService(static function (int $delay): void {
				}),
				$urlGenerator,
			),
			$this->buildPadResponseService($urlGenerator),
			$this->publicErrorMapper($this->untranslated(), $logger),
			$session ?? $this->createMock(ISession::class),
			$cookies ?? new RecordingCookieHeaders(),
		);
	}

	private function buildPadResponseService(IURLGenerator $urlGenerator): PadResponseService {
		return new PadResponseService($urlGenerator, $this->createMock(\OCA\EtherpadNextcloud\Service\AppConfigService::class), $this->untranslated());
	}

	/** English, as t() gets it. */
	private function untranslated(): \OCP\IL10N {
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		return $l10n;
	}
}
