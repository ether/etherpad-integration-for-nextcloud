<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OC\Security\CSRF\CsrfToken;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\EmbedResponseBuilder;
use PHPUnit\Framework\TestCase;

class EmbedResponseBuilderTest extends TestCase {
	public function testBuildMergesBaseDataIntoTemplateParams(): void {
		$builder = $this->buildBuilder(['https://portal.example.test']);

		$response = $builder->build('embed', ['file_id' => 17]);
		$params = $response->getParams();

		$this->assertSame('embed', $response->getTemplateName());
		$this->assertSame('blank', $response->getRenderAs());
		$this->assertSame(17, $params['file_id']);
		$this->assertSame('csrf-token-value', $params['requesttoken']);
		$this->assertSame(['https://portal.example.test'], $params['trusted_embed_origins']);
	}

	public function testBuildAppliesFrameAncestorPolicyFromTrustedOrigins(): void {
		$builder = $this->buildBuilder([
			'https://portal-a.example.test',
			'https://portal-b.example.test',
		]);

		$response = $builder->build('embed', []);
		$policy = $response->getContentSecurityPolicy();

		$this->assertNotNull($policy);
		$this->assertSame(
			['https://portal-a.example.test', 'https://portal-b.example.test'],
			$policy->getAllowedFrameAncestorDomains(),
		);
	}

	public function testBaseDataIsCachedAcrossMultipleBuildCalls(): void {
		$appConfigService = $this->createMock(AppConfigService::class);
		// Only called once even though build() runs twice.
		$appConfigService->expects($this->once())
			->method('getTrustedEmbedOrigins')
			->willReturn(['https://portal.example.test']);

		$builder = new EmbedResponseBuilder(
			new CsrfTokenManager(new CsrfToken('csrf-token-value')),
			$appConfigService,
		);

		$builder->build('embed', []);
		$builder->build('embed-create', []);

		$this->addToAssertionCount(1);
	}

	/** @param list<string> $trustedOrigins */
	private function buildBuilder(array $trustedOrigins): EmbedResponseBuilder {
		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->method('getTrustedEmbedOrigins')->willReturn($trustedOrigins);

		return new EmbedResponseBuilder(
			new CsrfTokenManager(new CsrfToken('csrf-token-value')),
			$appConfigService,
		);
	}
}
