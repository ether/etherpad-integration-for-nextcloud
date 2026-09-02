<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
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

		$readOnly = $service->openResponse($this->buildTarget(isReadOnlySnapshot: true))->getData();
		$editable = $service->openResponse($this->buildTarget(isReadOnlySnapshot: false))->getData();

		$this->assertTrue($readOnly['is_readonly_snapshot']);
		$this->assertFalse($editable['is_readonly_snapshot']);
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

		$readOnly = $service->openResponse($this->buildTarget(isReadOnlySnapshot: true))->getData();
		$editable = $service->openResponse($this->buildTarget(isReadOnlySnapshot: false))->getData();

		$this->assertSame('', $readOnly['sync_url']);
		$this->assertSame('', $readOnly['sync_status_url']);
		$this->assertSame('/sync/42', $editable['sync_url']);
	}

	private function buildTarget(bool $isReadOnlySnapshot): \OCA\EtherpadNextcloud\Service\PadOpenTarget {
		return new \OCA\EtherpadNextcloud\Service\PadOpenTarget(
			file: '/Test.pad',
			fileId: 42,
			padId: 'test',
			accessMode: 'protected',
			padUrl: 'https://pad.example.test/p/test',
			isExternal: false,
			originalPadUrl: '',
			snapshotText: $isReadOnlySnapshot ? 'snapshot' : '',
			snapshotHtml: '',
			url: $isReadOnlySnapshot ? '' : 'https://pad.example.test/p/test',
			cookieHeader: '',
			isReadOnlySnapshot: $isReadOnlySnapshot,
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
			snapshotText: '',
			snapshotHtml: '',
			url: 'https://pad.example.test/p/test',
			cookieHeader: 'sessionID=s.test; Path=/',
			isReadOnlySnapshot: false,
		);
		$response = (new PadResponseService($urlGenerator, $appConfigService, $this->l10nEcho()))->openResponse($target);

		$data = $response->getData();
		$this->assertArrayNotHasKey('cookie_header', $data);
		$this->assertSame('/sync/42', $data['sync_url']);
		$this->assertSame('/sync-status/42', $data['sync_status_url']);
		$this->assertSame(45, $data['sync_interval_seconds']);
		$this->assertSame('sessionID=s.test; Path=/', $response->getHeaders()['Set-Cookie']);
	}

	public function testLifecycleSkippedResponseUsesConflictStatus(): void {
		$response = (new PadResponseService(
			$this->createMock(IURLGenerator::class),
			$this->createMock(AppConfigService::class),
			$this->l10nEcho(),
		))->lifecycleResponse([
			'status' => LifecycleService::RESULT_SKIPPED,
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
