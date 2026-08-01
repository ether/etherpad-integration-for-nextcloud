<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminHealthCheckException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\CookieDomainDecision;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\EtherpadHealthCheckService;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class EtherpadHealthCheckServiceTest extends TestCase {
	/**
	 * The verdict follows the submitted form, not the stored configuration: a
	 * toggle the admin just flipped has to count before it is saved. Both
	 * directions matter, so the pair below covers on and off.
	 */
	public function testCheckReportsACookieProblemWhenProtectedPadsAreSubmittedAsEnabled(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 3]);

		// Nextcloud and Etherpad on unrelated domains: the API answers, but no
		// session cookie can span both hosts.
		// No cookie domain saved, so the policy derives one — and cannot.
		$result = $this->buildService($etherpad, $this->pendingCounts(0), 'https://cloud.example.test')
			->check($this->settings('https://pad.unrelated.test', true, ''));

		$this->assertSame(3, $result->padCount);
		$this->assertNotNull($result->cookieDomain);
		$this->assertFalse($result->cookieDomain->isOk());
		$this->assertSame(CookieDomainDecision::REASON_NO_COMMON_PARENT, $result->cookieDomain->reason);
		$this->assertSame('cloud.example.test', $result->cookieDomain->nextcloudHost);
		$this->assertSame('pad.unrelated.test', $result->cookieDomain->etherpadHost);
	}

	public function testCheckSkipsTheCookieVerdictWhenProtectedPadsAreSubmittedAsDisabled(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);

		$result = $this->buildService($etherpad, $this->pendingCounts(0))
			->check($this->settings('https://pad.unrelated.test', false));

		$this->assertNull($result->cookieDomain);
	}

	private function buildService(
		EtherpadClient $etherpad,
		PendingDeleteRetryService $pending,
		string $nextcloudUrl = 'https://cloud.example.test',
	): EtherpadHealthCheckService {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn($nextcloudUrl);
		return new EtherpadHealthCheckService(
			$etherpad,
			$pending,
			$this->buildL10n(),
			new CookieDomainPolicy(),
			$urlGenerator,
		);
	}

	public function testCheckReturnsHealthCheckResult(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('healthCheck')
			->with('https://pad-api.example.test', 'key', '1.3.0')
			->willReturn(['pad_count' => 42]);

		$pending = $this->createMock(PendingDeleteRetryService::class);
		$pending->expects($this->once())->method('countPendingDeletes')->willReturn(3);

		$result = ($this->buildService($etherpad, $pending))->check($this->settings());

		$this->assertSame(42, $result->padCount);
		$this->assertSame(3, $result->pendingDeleteCount);
		$this->assertSame('https://pad-api.example.test/api/1.3.0/listAllPads', $result->target);
	}

	public function testCheckDefaultsMissingPadCountToZero(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn([]);

		$result = $this->buildService($etherpad, $this->pendingCounts(0))->check($this->settings());

		$this->assertSame(0, $result->padCount);
	}

	public function testCheckRoundsLatencyMilliseconds(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);
		$ticks = [100.0000, 100.1246];

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.test');
		$service = new class(
			$etherpad,
			$this->pendingCounts(0),
			$this->buildL10n(),
			new CookieDomainPolicy(),
			$urlGenerator,
			$ticks,
		) extends EtherpadHealthCheckService {
			/** @param list<float> $ticks */
			public function __construct(
				EtherpadClient $etherpadClient,
				PendingDeleteRetryService $pendingDeleteRetryService,
				IL10N $l10n,
				CookieDomainPolicy $cookieDomainPolicy,
				IURLGenerator $urlGenerator,
				private array $ticks,
			) {
				parent::__construct($etherpadClient, $pendingDeleteRetryService, $l10n, $cookieDomainPolicy, $urlGenerator);
			}

			protected function now(): float {
				return array_shift($this->ticks) ?? 100.1246;
			}
		};

		$result = $service->check($this->settings());

		$this->assertSame(125, $result->latencyMs);
	}

	public function testCheckAddsAuthMethodHintForApiKeyMismatch(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException(new EtherpadClientException('no or wrong API Key'));

		$this->expectException(AdminHealthCheckException::class);
		$this->expectExceptionMessage('authenticationMethod');

		($this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class)))
			->check($this->settings());
	}

	public function testCheckDoesNotAddAuthHintForUnrelatedFailures(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException(new EtherpadClientException('Etherpad transport error: getaddrinfo for pad.bogus failed'));

		try {
			($this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class)))
				->check($this->settings());
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertStringContainsString('getaddrinfo', $e->getMessage());
			$this->assertStringNotContainsString('authenticationMethod', $e->getMessage());
		}
	}

	/** @return iterable<string,array{0:string,1:string}> */
	public static function hintCaseProvider(): iterable {
		yield 'dns failure' => [
			'Etherpad transport error: php_network_getaddresses: getaddrinfo for pad.example failed',
			'did not resolve',
		];
		yield 'connection refused' => [
			'Etherpad transport error: Connection refused',
			'Etherpad does not appear to be running',
		];
		yield 'timeout' => [
			'Etherpad transport error: stream_socket_client(): timed out',
			'Connection timed out',
		];
		yield 'tls handshake' => [
			'Etherpad transport error: SSL operation failed with code 1. OpenSSL Error',
			'TLS handshake failed',
		];
		yield 'http 401' => [
			'Etherpad API HTTP error (401)',
			'rejected the API key',
		];
		yield 'http 404' => [
			'Etherpad API HTTP error (404)',
			'API endpoint not found',
		];
		yield 'http 502' => [
			'Etherpad API HTTP error (502)',
			'server error',
		];
		yield 'invalid json' => [
			'Invalid JSON response from Etherpad API.',
			'non-JSON',
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('hintCaseProvider')]
	public function testCheckAttachesActionableHintForKnownFailures(string $clientMessage, string $expectedHintFragment): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException(new EtherpadClientException($clientMessage));

		try {
			($this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class)))
				->check($this->settings());
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertStringContainsString($clientMessage, $e->getMessage());
			$this->assertStringContainsString($expectedHintFragment, $e->getMessage());
		}
	}

	public function testCheckMatchesHintAgainstWrappedPreviousException(): void {
		// EtherpadClient::apiCall wraps transport-level failures as
		// 'Etherpad API request failed: <method>' with the real cause as
		// previous. The hint matcher must read through the chain.
		$inner = new EtherpadClientException('Etherpad transport error: php_network_getaddresses: getaddrinfo for pad.does-not-exist.invalid failed');
		$wrapped = new EtherpadClientException('Etherpad API request failed: listAllPads', 0, $inner);

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException($wrapped);

		try {
			($this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class)))
				->check($this->settings());
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertStringContainsString('Etherpad API request failed: listAllPads', $e->getMessage());
			$this->assertStringContainsString('getaddrinfo', $e->getMessage());
			$this->assertStringContainsString('did not resolve', $e->getMessage());
		}
	}

	public function testCheckAttachesNoHintForUnrecognisedFailureShape(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException(new EtherpadClientException('something completely unexpected happened'));

		try {
			($this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class)))
				->check($this->settings());
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertSame('Etherpad connection test failed: something completely unexpected happened', $e->getMessage());
		}
	}

	private function settings(
		string $etherpadHost = 'https://pad.example.test',
		bool $enableProtectedPads = true,
		string $cookieDomain = '.example.test',
	): ValidatedAdminSettings {
		return new ValidatedAdminSettings(
			$etherpadHost,
			'https://pad-api.example.test',
			$cookieDomain,
			'key',
			'key',
			'1.3.0',
			120,
			true,
			true,
			'',
			'',
			$enableProtectedPads,
		);
	}

	private function pendingCounts(int $pendingDeleteCount): PendingDeleteRetryService {
		$pending = $this->createMock(PendingDeleteRetryService::class);
		$pending->method('countPendingDeletes')->willReturn($pendingDeleteCount);
		return $pending;
	}

	private function buildL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				foreach ($parameters as $key => $value) {
					$text = str_replace('{' . $key . '}', (string)$value, $text);
				}
				return $text;
			}
		);
		return $l10n;
	}
}
