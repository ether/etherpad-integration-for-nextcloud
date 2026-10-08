<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\LifecycleResult;
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
	 * A viewer must not be told where to sync. Syncing writes the pad back
	 * into the `.pad` file, which is the one thing a read-only share may
	 * not do — and the client flushes on a timer and on every tab switch,
	 * so each one would fail on the filesystem and be logged as an error
	 * for as long as the tab is open.
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
		$liveReadOnly = $service->openResponse($this->buildTarget(isReadOnlyView: false), new RecordingCookieHeaders())->getData();
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

	private function buildTarget(bool $isReadOnlyView, bool $mayWrite = false, string $cookieHeader = ''): \OCA\EtherpadNextcloud\Service\PadOpenTarget {
		return new \OCA\EtherpadNextcloud\Service\PadOpenTarget(
			file: '/Test.pad',
			fileId: 42,
			padId: 'test',
			accessMode: 'protected',
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
