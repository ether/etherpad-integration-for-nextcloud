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

	public function testOpenResponseMovesCookieHeaderOutOfPayload(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturnMap([
				['etherpad_nextcloud.pad.syncById', ['fileId' => 42], '/sync/42'],
				['etherpad_nextcloud.pad.syncStatusById', ['fileId' => 42], '/sync-status/42'],
			]);

		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->method('getSyncIntervalSeconds')->willReturn(45);

		$response = (new PadResponseService($urlGenerator, $appConfigService, $this->l10nEcho()))->openResponse([
			'file_id' => 42,
			'url' => 'https://pad.example.test/p/test',
			'cookie_header' => 'sessionID=s.test; Path=/',
		]);

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
