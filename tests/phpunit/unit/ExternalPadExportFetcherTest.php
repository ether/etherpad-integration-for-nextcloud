<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\ExternalPadExportNotFoundException;
use OCA\EtherpadNextcloud\Exception\ExternalPadHttpErrorException;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCP\IConfig;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

class ExternalPadExportFetcherTest extends TestCase {
	public function testNormalizeAndValidateExternalPublicPadUrlCanonicalizesHttpsUrl(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/My Pad');

		$this->assertSame('https://1.1.1.1', $result['origin']);
		$this->assertSame('My Pad', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/My%20Pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlKeepsLiteralPlusInPadId(): void {
		// `+` is literal in URL path segments. Using urldecode() previously
		// turned `team+pad` into pad-id `team pad`, then re-emitted
		// `/p/team%20pad` which hits a different / non-existent pad.
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());
		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team+meeting');
		$this->assertSame('team+meeting', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/team%2Bmeeting', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlDecodesPercentEncodedPlus(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());
		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team%2Bmeeting');
		$this->assertSame('team+meeting', $result['pad_id']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlAcceptsMatchingAllowlistedOriginWithPort(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:8443/p/public-pad');

		$this->assertSame('https://1.1.1.1:8443', $result['origin']);
		$this->assertSame('https://1.1.1.1:8443/p/public-pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsNonMatchingAllowlistedOriginPort(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$this->expectException(ExternalPadException::class);
		$this->expectExceptionMessage('External pad host is not in the allowlist.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:9443/p/public-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsAPadIdWithANewline(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		$this->expectException(ExternalPadException::class);
		$fetcher->normalizeAndValidateExternalPublicPadUrl(
			'https://1.1.1.1/p/a%0Apad_id:%20g.victim$secret',
		);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsAControlCharacterBeforeThePadId(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		$this->expectException(ExternalPadException::class);
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/base%01/p/demo');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsProtectedPadIds(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		$this->expectException(ExternalPadException::class);
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

		$fetcher = $this->fetcher($config);

		$this->expectException(ExternalPadException::class);
		$this->expectExceptionMessage('External pad linking is disabled by admin settings.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/public-pad');
	}

	/**
	 * Each refusal carries the reason the user is told, whatever its
	 * message says for the log.
	 */
	public function testEachRefusalCarriesItsReason(): void {
		$cases = [
			'no https' => ['http://1.1.1.1/p/pad', '', ExternalPadException::INVALID_URL],
			'another scheme' => ['ftp://1.1.1.1/p/pad', '', ExternalPadException::INVALID_URL],
			'no host' => ['https:///p/pad', '', ExternalPadException::INVALID_URL],
			'credentials' => ['https://user:secret@1.1.1.1/p/pad', '', ExternalPadException::CREDENTIALS_IN_URL],
			'no /p/' => ['https://1.1.1.1/pad/pad', '', ExternalPadException::NOT_A_PAD_URL],
			'only the server' => ['https://1.1.1.1', '', ExternalPadException::NOT_A_PAD_URL],
			'a group pad' => ['https://1.1.1.1/p/g.group$pad', '', ExternalPadException::NOT_PUBLIC],
			'not on the allowlist' => ['https://1.1.1.1:9443/p/pad', 'https://1.1.1.1:8443', ExternalPadException::NOT_ALLOWED],
			'a local host' => ['https://pad.localhost/p/pad', '', ExternalPadException::LOCAL_ADDRESS],
			'a private address' => ['https://10.0.0.7/p/pad', '', ExternalPadException::LOCAL_ADDRESS],
			'no pad after /p/' => ['https://1.1.1.1/p/%20', '', ExternalPadException::NOT_A_PAD_URL],
			'a name without records' => ['https://pad.example/p/pad', '', ExternalPadException::UNRESOLVED, []],
			'a name without an address' => ['https://pad.example/p/pad', '', ExternalPadException::UNRESOLVED, [['host' => 'pad.example', 'type' => 'A']]],
			'a name for a private address' => ['https://pad.example/p/pad', '', ExternalPadException::LOCAL_ADDRESS, [['ip' => '1.1.1.1'], ['ipv6' => 'fd00::7']]],
		];
		foreach ($cases as $case => $row) {
			// What a name lookup finds, where the case gets that far.
			[$url, $allowlist, $reason, $records] = $row + [3 => null];
			try {
				$this->fetcher($this->buildExternalEnabledConfig($allowlist), $records)->normalizeAndValidateExternalPublicPadUrl($url);
				$this->fail($case . ': not refused');
			} catch (ExternalPadException $e) {
				$this->assertSame($reason, $e->reason(), $case);
			}
		}

		$disabled = $this->createMock(IConfig::class);
		$disabled->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $key === 'allow_external_pads' ? 'no' : $default);
		try {
			$this->fetcher($disabled)->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/pad');
			$this->fail('switched off: not refused');
		} catch (ExternalPadException $e) {
			$this->assertSame(ExternalPadException::DISABLED, $e->reason());
		}

		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());
		foreach ([
			[500, ExternalPadException::HTTP_ERROR, 500],
			[401, ExternalPadException::HTTP_ERROR, 401],
			[400, ExternalPadException::HTTP_ERROR, 400],
			[300, ExternalPadException::REDIRECTED, null],
			[302, ExternalPadException::REDIRECTED, null],
			[307, ExternalPadException::REDIRECTED, null],
			[399, ExternalPadException::REDIRECTED, null],
			[404, ExternalPadException::NOT_FOUND, null],
		] as [$status, $reason, $httpStatus]) {
			try {
				(new \ReflectionMethod(ExternalPadExportFetcher::class, 'assertSuccessfulExportStatus'))->invoke($fetcher, $status);
				$this->fail($status . ': not refused');
			} catch (ExternalPadException $e) {
				$this->assertSame([$reason, $httpStatus], [$e->reason(), $e instanceof ExternalPadHttpErrorException ? $e->httpStatus() : null], (string)$status);
			}
		}
		foreach ([['', 'txt'], ['text/html', 'txt'], ['image/png', 'txt'], ['application/json', 'html']] as [$contentType, $format]) {
			try {
				(new \ReflectionMethod(ExternalPadExportFetcher::class, 'assertAllowedExternalExportContentType'))->invoke($fetcher, $contentType, $format);
				$this->fail($contentType . ' for ' . $format . ': not refused');
			} catch (ExternalPadException $e) {
				$this->assertSame(ExternalPadException::UNEXPECTED_ANSWER, $e->reason(), $contentType . ' for ' . $format);
			}
		}

		// No answer at all: a certificate not trusted, which a later try does
		// not mend, or the server not reached. Untested here: what cURL
		// itself returns, which needs a server, a cURL that cannot start a
		// request, and PHP without cURL.
		$transport = new \ReflectionMethod(ExternalPadExportFetcher::class, 'transportReason');
		$this->assertSame(ExternalPadException::UNTRUSTED_CERTIFICATE, $transport->invoke(null, [7, 60]));
		$this->assertSame(ExternalPadException::UNTRUSTED_CERTIFICATE, $transport->invoke(null, [51]));
		$this->assertSame(ExternalPadException::UNREACHABLE, $transport->invoke(null, [7, 28]));
		$this->assertSame(ExternalPadException::UNREACHABLE, $transport->invoke(null, []));
	}

	/**
	 * The budget is shared with everything before the request, name
	 * resolution included, so an attempt that no longer fits is not made at
	 * all rather than started with the full timeout.
	 */
	public function testAnExhaustedBudgetStopsBeforeAnyAttempt(): void {
		$send = new \ReflectionMethod(ExternalPadExportFetcher::class, 'sendPinnedPublicGetRequest');
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		try {
			$send->invoke(
				$fetcher,
				'https://1.1.1.1/p/Test/export/html',
				'1.1.1.1',
				443,
				['1.1.1.1'],
				'html',
				// Already spent: a slow lookup leaves nothing for the transfer.
				// Against the clock the fetcher reads, not the wall clock — the
				// two are the same only by accident.
				FixedClock::NOW - 1.0,
			);
			$this->fail('an attempt was made');
		} catch (ExternalPadException $e) {
			$this->assertStringContainsString('no time left', $e->getMessage());
			// No answer from the server, which a later try may get.
			$this->assertSame(ExternalPadException::UNREACHABLE, $e->reason());
		}
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
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		if (!$accepted) {
			$this->expectException(ExternalPadException::class);
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
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

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
			'301 is a redirect, not content' => [301, ExternalPadException::class],
			'302 is a redirect, not content' => [302, ExternalPadException::class],
			'307 is a redirect, not content' => [307, ExternalPadException::class],
			'401 is a login wall' => [401, ExternalPadException::class],
			'404 says the pad is not exportable' => [404, ExternalPadExportNotFoundException::class],
			'500 is the far side failing' => [500, ExternalPadException::class],
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

	/**
	 * The fetcher, with what a name lookup finds given instead of asked of
	 * the network: a test that resolves a real name is green offline, slow
	 * behind a dead nameserver and red behind one that answers every name.
	 * A lookup a test did not give an answer for fails it.
	 *
	 * @param ?list<array<string, string>> $records
	 */
	private function fetcher(IConfig $config, ?array $records = null): ExternalPadExportFetcher {
		return new class($config, new FixedClock(), $records) extends ExternalPadExportFetcher {
			/** @param ?list<array<string, string>> $records */
			public function __construct(IConfig $config, FixedClock $clock, private ?array $records) {
				parent::__construct($config, $clock);
			}

			protected function lookUp(string $host): array {
				if ($this->records === null) {
					throw new \LogicException('A lookup of ' . $host . ' the test gave no answer for.');
				}
				return $this->records;
			}
		};
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
