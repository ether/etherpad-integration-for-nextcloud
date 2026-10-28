<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\LifecycleResult;
use OCA\EtherpadNextcloud\Service\PadMeta;
use OCA\EtherpadNextcloud\Service\PadOpenTarget;
use OCA\EtherpadNextcloud\Service\PadResolution;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Tests\Support\RecordingCookieHeaders;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PadResponseServiceTest extends TestCase {
	private function l10nEcho(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $text);
		return $l10n;
	}

	public function testWithViewerAndEmbedUrlsAddsExpectedRoutes(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturnMap([
				['files.view.index', [], '/apps/files'],
				['etherpad_nextcloud.embed.showById', ['fileId' => 42], '/apps/etherpad/embed/by-id/42'],
			]);

		$result = (new PadResponseService($urlGenerator, $this->createMock(AppConfigService::class), $this->l10nEcho()))
			->withViewerAndEmbedUrls([
				'file_id' => 42,
				'file' => '/Projects/Test Pad.pad',
			]);

		$this->assertSame('/apps/files/files/42?dir=%2FProjects&editing=false&openfile=true', $result['viewer_url']);
		$this->assertSame('/apps/etherpad/embed/by-id/42', $result['embed_url']);
	}

	/**
	 * Each address under its own key: the private fields reach the client
	 * through their methods, not swapped and not dropped.
	 */
	public function testOpenResponseAnswersWithTheTargetsFileAndItsAddresses(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/route');
		$target = new PadOpenTarget(
			file: '/Shared/Remote.pad',
			fileId: 42,
			padId: 'ext.Remote',
			accessMode: 'public',
			padUrl: 'https://pad.remote.test/p/Remote',
			isExternal: true,
			originalPadUrl: 'https://pad.remote.test/p/Remote?lang=de',
			url: 'https://pad.remote.test/p/Remote#open',
			cookieHeader: '',
			isReadOnlyView: false,
			mayWrite: true,
		);

		$data = (new PadResponseService($urlGenerator, $this->createMock(AppConfigService::class), $this->l10nEcho()))
			->openResponse($target, new RecordingCookieHeaders())->getData();

		$this->assertSame([
			'file' => '/Shared/Remote.pad',
			'file_id' => 42,
			'pad_id' => 'ext.Remote',
			'access_mode' => 'public',
			'pad_url' => 'https://pad.remote.test/p/Remote',
			'is_external' => true,
			'is_readonly_view' => false,
			'original_pad_url' => 'https://pad.remote.test/p/Remote?lang=de',
			'url' => 'https://pad.remote.test/p/Remote#open',
		], array_slice($data, 0, 9));
	}

	public function testMetaResponseAnswersAPadWithItsAddressesAndAFileWithItsPlace(): void {
		$service = new PadResponseService($this->viewerRoutes(), $this->createMock(AppConfigService::class), $this->l10nEcho());

		$this->assertSame([
			'is_pad' => true,
			'is_pad_mime' => true,
			'file_id' => 42,
			'name' => 'Remote.pad',
			'path' => '/Shared/Remote.pad',
			'access_mode' => 'public',
			'is_external' => true,
			'pad_id' => 'ext.Remote',
			'pad_url' => 'https://pad.remote.test/p/Remote',
			'public_open_url' => 'https://pad.remote.test/p/Remote?open',
			'viewer_url' => '/apps/files/files/42?dir=%2FShared&editing=false&openfile=true',
			'embed_url' => '/apps/etherpad/embed/by-id/42',
		], $service->metaResponse(new PadMeta(
			true, 42, 'Remote.pad', '/Shared/Remote.pad', true, 'public', true, 'ext.Remote',
			'https://pad.remote.test/p/Remote', 'https://pad.remote.test/p/Remote?open',
		)));
		$this->assertSame(
			['is_pad' => false, 'file_id' => 42, 'name' => 'Notes.txt', 'path' => '/Shared/Notes.txt'],
			$service->metaResponse(new PadMeta(false, 42, 'Notes.txt', '/Shared/Notes.txt')),
		);
	}

	public function testResolveResponseAnswersAPadWithItsAddressAndAFileWithWhatWasAsked(): void {
		$service = new PadResponseService($this->viewerRoutes(), $this->createMock(AppConfigService::class), $this->l10nEcho());

		$this->assertSame([
			'is_pad' => true,
			'is_pad_mime' => true,
			'file_id' => 42,
			'path' => '/Shared/Remote.pad',
			'access_mode' => 'public',
			'is_external' => true,
			'public_open_url' => 'https://pad.remote.test/p/Remote?open',
			'viewer_url' => '/apps/files/files/42?dir=%2FShared&editing=false&openfile=true',
		], $service->resolveResponse(new PadResolution(true, 42, '/Shared/Remote.pad', true, 'public', true, 'https://pad.remote.test/p/Remote?open')));
		$this->assertSame(['is_pad' => false, 'file_id' => 42], $service->resolveResponse(new PadResolution(false, 42)));
	}

	private function viewerRoutes(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnMap([
			['files.view.index', [], '/apps/files'],
			['etherpad_nextcloud.embed.showById', ['fileId' => 42], '/apps/etherpad/embed/by-id/42'],
		]);
		return $urlGenerator;
	}

	/**
	 * The viewer decides what to render from this one field, so a target
	 * that withholds the pad must be reported as withholding it — otherwise
	 * the service does the right thing and the client still asks for an
	 * editor it was never given a URL for.
	 */
	public function testOpenResponseCarriesTheReadOnlyDecisionToTheClient(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/sync/42');
		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->method('getSyncIntervalSeconds')->willReturn(45);
		$service = new PadResponseService($urlGenerator, $appConfigService, $this->l10nEcho());

		$readOnly = $service->openResponse($this->buildTarget(isReadOnlyView: true), new RecordingCookieHeaders())->getData();
		$editable = $service->openResponse($this->buildTarget(isReadOnlyView: false, mayWrite: true), new RecordingCookieHeaders())->getData();

		$this->assertTrue($readOnly['is_readonly_view']);
		$this->assertFalse($editable['is_readonly_view']);
	}

	/**
	 * The sync addresses follow the write permission, not the view, so both
	 * read-only views are checked.
	 */
	public function testOpenResponseWithholdsTheSyncUrlsFromAViewer(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/sync/42');
		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->method('getSyncIntervalSeconds')->willReturn(45);
		$service = new PadResponseService($urlGenerator, $appConfigService, $this->l10nEcho());

		$ownView = $service->openResponse($this->buildTarget(isReadOnlyView: true), new RecordingCookieHeaders())->getData();
		// A public pad opened read-only: Etherpad's own read-only page in an
		// iframe rather than our viewer — and it must not sync either.
		$liveReadOnly = $service->openResponse($this->buildTarget(isReadOnlyView: false, accessMode: 'public'), new RecordingCookieHeaders())->getData();
		$editable = $service->openResponse($this->buildTarget(isReadOnlyView: false, mayWrite: true), new RecordingCookieHeaders())->getData();

		$this->assertSame('', $ownView['sync_url']);
		$this->assertSame('', $ownView['sync_status_url']);
		$this->assertSame('', $liveReadOnly['sync_url'], 'not our viewer, still not writable');
		$this->assertSame('', $liveReadOnly['sync_status_url']);
		$this->assertSame('/sync/42', $editable['sync_url']);
	}

	/**
	 * The view exists to show the pad as it is now, so a cached copy would
	 * defeat the point of it — and since the body is answered per reader
	 * after an access check, a shared cache must not keep it at all.
	 */
	public function testPadContentIsAnsweredWithCachingTurnedOff(): void {
		$service = new PadResponseService(
			$this->createMock(IURLGenerator::class),
			$this->createMock(AppConfigService::class),
			$this->l10nEcho(),
		);

		$response = $service->padContentResponse(new \OCA\EtherpadNextcloud\Service\LivePadHtml('<p>Now</p>', false));

		$this->assertSame('<p>Now</p>', $response->getData()['html']);
		$this->assertFalse($response->getData()['is_empty']);
		$this->assertStringContainsString('no-store', $response->getHeaders()['Cache-Control']);
		$this->assertStringContainsString('private', $response->getHeaders()['Cache-Control']);
	}

	private function buildTarget(bool $isReadOnlyView, bool $mayWrite = false, string $cookieHeader = '', string $accessMode = 'protected'): \OCA\EtherpadNextcloud\Service\PadOpenTarget {
		return new \OCA\EtherpadNextcloud\Service\PadOpenTarget(
			file: '/Test.pad',
			fileId: 42,
			padId: 'test',
			accessMode: $accessMode,
			padUrl: 'https://pad.example.test/p/test',
			isExternal: false,
			originalPadUrl: '',
			url: $isReadOnlyView ? '' : 'https://pad.example.test/p/test',
			cookieHeader: $cookieHeader,
			isReadOnlyView: $isReadOnlyView,
			mayWrite: $mayWrite,
		);
	}

	public function testOpenResponseMovesCookieHeaderOutOfPayload(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturnMap([
				['etherpad_nextcloud.padLifecycle.syncById', ['fileId' => 42], '/sync/42'],
				['etherpad_nextcloud.padLifecycle.syncStatusById', ['fileId' => 42], '/sync-status/42'],
			]);

		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->method('getSyncIntervalSeconds')->willReturn(45);

		$target = new \OCA\EtherpadNextcloud\Service\PadOpenTarget(
			file: '/Test.pad',
			fileId: 42,
			padId: 'test',
			accessMode: 'protected',
			padUrl: 'https://pad.example.test/p/test',
			isExternal: false,
			originalPadUrl: '',
			url: 'https://pad.example.test/p/test',
			cookieHeader: 'sessionID=s.test; Path=/',
			isReadOnlyView: false,
			mayWrite: true,
		);
		$cookies = new RecordingCookieHeaders();
		$response = (new PadResponseService($urlGenerator, $appConfigService, $this->l10nEcho()))->openResponse($target, $cookies);

		$data = $response->getData();
		$this->assertArrayNotHasKey('cookie_header', $data);
		$this->assertSame('/sync/42', $data['sync_url']);
		$this->assertSame('/sync-status/42', $data['sync_status_url']);
		$this->assertSame(45, $data['sync_interval_seconds']);
		// Beside Nextcloud's cookies, not among the response's headers,
		// where it would replace them.
		$this->assertSame([['Set-Cookie: sessionID=s.test; Path=/', false]], $cookies->sent);
		$this->assertArrayNotHasKey('Set-Cookie', $response->getHeaders());
	}

	/**
	 * An answer that could not be built leaves no session cookie behind:
	 * the error answered in its place would carry it, and the browser
	 * would keep a session for an open that failed.
	 */
	public function testNoSessionCookieGoesOutWithAnAnswerThatCouldNotBeBuilt(): void {
		$appConfigService = $this->createMock(AppConfigService::class);
		// The last part of the answer to be built.
		$appConfigService->method('getSyncIntervalSeconds')->willThrowException(new \RuntimeException('database gone'));
		$cookies = new RecordingCookieHeaders();

		try {
			(new PadResponseService($this->createMock(IURLGenerator::class), $appConfigService, $this->l10nEcho()))
				->openResponse($this->buildTarget(isReadOnlyView: false, mayWrite: true, cookieHeader: 'sessionID=s.test; Path=/'), $cookies);
			$this->fail('the answer was built');
		} catch (\RuntimeException $e) {
			$this->assertSame('database gone', $e->getMessage());
		}

		$this->assertSame([], $cookies->sent);
	}

	public function testLifecycleSkippedResponseUsesConflictStatus(): void {
		$response = (new PadResponseService(
			$this->createMock(IURLGenerator::class),
			$this->createMock(AppConfigService::class),
			$this->l10nEcho(),
		))->lifecycleResponse([
			'status' => LifecycleResult::SKIPPED,
			'reason' => 'binding_not_active',
		]);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	public function testBindingErrorMessageExplainsMissingBinding(): void {
		$message = (new PadResponseService(
			$this->createMock(IURLGenerator::class),
			$this->createMock(AppConfigService::class),
			$this->l10nEcho(),
		))->bindingErrorMessage(new MissingBindingException('No binding exists for this file.'));

		$this->assertSame('This .pad file has no matching pad in this Nextcloud.', $message);
	}
}
