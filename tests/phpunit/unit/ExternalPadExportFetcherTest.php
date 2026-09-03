<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\ExternalPadExportNotFoundException;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class ExternalPadExportFetcherTest extends TestCase {
	public function testNormalizeAndValidateExternalPublicPadUrlCanonicalizesHttpsUrl(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());

		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/My Pad');

		$this->assertSame('https://1.1.1.1', $result['origin']);
		$this->assertSame('My Pad', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/My%20Pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlKeepsLiteralPlusInPadId(): void {
		// `+` is literal in URL path segments. Using urldecode() previously
		// turned `team+pad` into pad-id `team pad`, then re-emitted
		// `/p/team%20pad` which hits a different / non-existent pad.
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());
		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team+meeting');
		$this->assertSame('team+meeting', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/team%2Bmeeting', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlDecodesPercentEncodedPlus(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());
		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team%2Bmeeting');
		$this->assertSame('team+meeting', $result['pad_id']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlAcceptsMatchingAllowlistedOriginWithPort(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:8443/p/public-pad');

		$this->assertSame('https://1.1.1.1:8443', $result['origin']);
		$this->assertSame('https://1.1.1.1:8443/p/public-pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsNonMatchingAllowlistedOriginPort(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad host is not in the allowlist.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:9443/p/public-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsProtectedPadIds(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Only public pad URLs can be linked from external servers.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/g.group$protected-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsWhenDisabledByAdmin(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'allow_external_pads') {
					return 'no';
				}
				return $default;
			}
		);

		$fetcher = new ExternalPadExportFetcher($config);

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad linking is disabled by admin settings.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/public-pad');
	}

	/**
	 * The rule that keeps an error page from being read as pad content.
	 *
	 * Widening it for the HTML export must not widen it for the text one:
	 * a foreign server answering a text export with an HTML error page and
	 * a 200 is the case it was written for.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('contentTypeCases')]
	public function testContentTypeIsAcceptedPerExportFormat(string $format, string $contentType, bool $accepted): void {
		$assert = new \ReflectionMethod(ExternalPadExportFetcher::class, 'assertAllowedExternalExportContentType');
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());

		if (!$accepted) {
			$this->expectException(EtherpadClientException::class);
		}
		$assert->invoke($fetcher, $contentType, $format);

		if ($accepted) {
			$this->addToAssertionCount(1);
		}
	}

	/**
	 * Redirects are not followed, and their bodies are not read either.
	 * A "Please sign in" page behind a 302 used to arrive as pad content
	 * once the HTML export stopped rejecting it on content type.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('statusCases')]
	public function testOnlyASuccessfulExportStatusIsAccepted(int $status, ?string $expectedException): void {
		$assert = new \ReflectionMethod(ExternalPadExportFetcher::class, 'assertSuccessfulExportStatus');
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());

		if ($expectedException !== null) {
			$this->expectException($expectedException);
		}
		$assert->invoke($fetcher, $status);

		if ($expectedException === null) {
			$this->addToAssertionCount(1);
		}
	}

	/** @return array<string,array{0:int,1:?string}> */
	public static function statusCases(): array {
		return [
			'200 is the export' => [200, null],
			'204 is still a success' => [204, null],
			'301 is a redirect, not content' => [301, EtherpadClientException::class],
			'302 is a redirect, not content' => [302, EtherpadClientException::class],
			'307 is a redirect, not content' => [307, EtherpadClientException::class],
			'401 is a login wall' => [401, EtherpadClientException::class],
			'404 says the pad is not exportable' => [404, ExternalPadExportNotFoundException::class],
			'500 is the far side failing' => [500, EtherpadClientException::class],
		];
	}

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function contentTypeCases(): array {
		return [
			'text export keeps refusing html' => ['txt', 'text/html; charset=utf-8', false],
			'text export takes plain text' => ['txt', 'text/plain; charset=utf-8', true],
			'text export takes a byte stream' => ['txt', 'application/octet-stream', true],
			'html export takes html' => ['html', 'text/html; charset=utf-8', true],
			'html export takes xhtml' => ['html', 'application/xhtml+xml', true],
			'html export refuses plain text' => ['html', 'text/plain', false],
			'html export refuses json' => ['html', 'application/json', false],
			'html export refuses a byte stream' => ['html', 'application/octet-stream', false],
			'a missing header is refused either way' => ['html', '', false],
		];
	}

	private function buildExternalEnabledConfig(string $externalPadAllowlist = ''): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use ($externalPadAllowlist): string {
				if ($appName !== 'etherpad_nextcloud') {
					return $default;
				}
				if ($key === 'allow_external_pads') {
					return 'yes';
				}
				if ($key === 'external_pad_allowlist') {
					return $externalPadAllowlist;
				}
				return $default;
			}
		);

		return $config;
	}
}
