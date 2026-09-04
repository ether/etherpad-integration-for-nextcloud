<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\PublicPadOpenService;
use PHPUnit\Framework\TestCase;

class PublicPadOpenServiceTest extends TestCase {
	public function testProtectedReadOnlyHandsOutNoPadAddress(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('buildPadUrl');
		$etherpad->expects($this->never())->method('getReadOnlyPadUrl');

		$result = $this->buildService(etherpadClient: $etherpad)->open(
			'g.group$pad',
			BindingService::ACCESS_PROTECTED,
			true,
			'token',
			false,
		);

		$this->assertSame('', $result->url);
		$this->assertTrue($result->isReadOnlyView);
		$this->assertSame('', $result->cookieHeader);
	}

	public function testProtectedWritableCreatesPublicShareSession(): void {
		$sessions = $this->createMock(PadSessionService::class);
		$sessions->expects($this->once())
			->method('createProtectedOpenContext')
			->with('public-share:token', 'Public share', 'g.group$pad', 3600)
			->willReturn(['url' => 'https://pad.example/p/g.group$pad', 'cookie' => ['name' => 'sessionID']]);
		$sessions->expects($this->once())
			->method('buildSetCookieHeader')
			->with(['name' => 'sessionID'])
			->willReturn('sessionID=abc; Path=/');

		$result = $this->buildService(padSessionService: $sessions)->open(
			'g.group$pad',
			BindingService::ACCESS_PROTECTED,
			false,
			'token',
			false,
		);

		$this->assertSame('https://pad.example/p/g.group$pad', $result->url);
		$this->assertSame('sessionID=abc; Path=/', $result->cookieHeader);
		$this->assertFalse($result->isReadOnlyView);
	}

	public function testExternalPublicPadReturnsNormalizedUrl(): void {
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->expects($this->once())
			->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://remote.example/p/Test')
			->willReturn(['pad_url' => 'https://remote.example/p/Test']);

		$result = $this->buildService(externalPadExportFetcher: $fetcher)->open(
			'ext.abc',
			BindingService::ACCESS_PUBLIC,
			true,
			'token',
			true,
			'https://remote.example/p/Test',
		);

		$this->assertSame('https://remote.example/p/Test', $result->url);
		$this->assertSame('https://remote.example/p/Test', $result->originalPadUrl);
	}

	public function testExternalProtectedMetadataIsRejected(): void {
		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad metadata requires public access_mode.');

		$this->buildService()->open(
			'ext.abc',
			BindingService::ACCESS_PROTECTED,
			false,
			'token',
			true,
			'https://remote.example/p/Test',
		);
	}

	public function testExternalPadWithoutUrlIsRejected(): void {
		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad URL metadata is missing or invalid.');

		$this->buildService()->open(
			'ext.abc',
			BindingService::ACCESS_PUBLIC,
			false,
			'token',
			true,
			'',
		);
	}

	public function testInternalReadOnlyUsesEtherpadReadOnlyUrl(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('getReadOnlyPadUrl')
			->with('public-pad')
			->willReturn('https://pad.example/p/r.public-pad');

		$result = $this->buildService(etherpadClient: $etherpad)->open(
			'public-pad',
			BindingService::ACCESS_PUBLIC,
			true,
			'token',
			false,
		);

		$this->assertSame('https://pad.example/p/r.public-pad', $result->url);
		$this->assertSame('', $result->cookieHeader);
	}

	public function testInternalWritableUsesPublicPadUrlWithoutSession(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('buildPadUrl')
			->with('public-pad')
			->willReturn('https://pad.example/p/public-pad');

		$sessions = $this->createMock(PadSessionService::class);
		$sessions->expects($this->never())->method('createProtectedOpenContext');

		$result = $this->buildService(etherpadClient: $etherpad, padSessionService: $sessions)->open(
			'public-pad',
			BindingService::ACCESS_PUBLIC,
			false,
			'token',
			false,
		);

		$this->assertSame('https://pad.example/p/public-pad', $result->url);
		$this->assertSame('', $result->cookieHeader);
	}

	private function buildService(
		?EtherpadClient $etherpadClient = null,
		?PadSessionService $padSessionService = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
	): PublicPadOpenService {
		return new PublicPadOpenService(
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			$padSessionService ?? $this->createMock(PadSessionService::class),
		);
	}
}
