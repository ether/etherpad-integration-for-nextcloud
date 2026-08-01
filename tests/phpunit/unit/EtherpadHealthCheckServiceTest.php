<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminHealthCheckException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\BaseUrlReachabilityCheck;
use OCA\EtherpadNextcloud\Service\CookieDomainDecision;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\EtherpadHealthCheckService;
use OCA\EtherpadNextcloud\Service\HealthCheckItem;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class EtherpadHealthCheckServiceTest extends TestCase {
	/**
	 * The verdict follows the submitted form, not the stored configuration: a
	 * toggle the admin just flipped has to count before it is saved. Both
	 * directions matter, so the pair below covers on and off, each across the
	 * two surfaces it reaches — the decision in the payload and the line the
	 * settings page renders.
	 */
	public function testCheckReportsACookieProblemWhenProtectedPadsAreSubmittedAsEnabled(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 3]);

		// Unrelated domains and no saved cookie domain: the API answers, but
		// the policy cannot derive one that spans both hosts.
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



	/** With no separate API URL the address failure is about the base URL. */
	public function testTransportFailureFallsBackToTheBaseUrlFieldWhenNoApiUrlIsSet(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException(new EtherpadClientException('Etherpad transport error: connection refused'));

		$settings = new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad.example.test',
			'.example.test',
			'key',
			'key',
			'1.3.0',
			120,
			true,
			true,
			'',
			'',
		);

		try {
			$this->buildService($etherpad, $this->pendingCounts(0))->check($settings);
			$this->fail('Expected AdminHealthCheckException');
		} catch (AdminHealthCheckException $e) {
			$this->assertSame('etherpad_host', $e->getField());
		}
	}

	public function testCheckReportsEachPartOnItsOwnLine(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);

		$result = $this->buildService($etherpad, $this->pendingCounts(0))->check($this->settings());

		$byId = [];
		foreach ($result->checks as $item) {
			$byId[$item->id] = $item;
		}
		// The protected-pads line is appended by the controller from
		// $cookieDomain, so summary and payload cannot disagree about it.
		$this->assertSame(['api', 'api_key', 'base_url'], array_keys($byId));
		$this->assertSame(HealthCheckItem::STATUS_OK, $byId['api']->status);
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
			$this->baseUrlCheck(),
			$urlGenerator,
		);
	}

	/**
	 * The base URL row does real I/O, so every case here stubs it. Its own
	 * behaviour is covered in BaseUrlReachabilityCheckTest.
	 */
	private function baseUrlCheck(string $status = HealthCheckItem::STATUS_OK): BaseUrlReachabilityCheck {
		$check = $this->createMock(BaseUrlReachabilityCheck::class);
		$check->method('check')->willReturn(new HealthCheckItem('base_url', $status, 'Etherpad base URL reachable'));
		return $check;
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
			$this->baseUrlCheck(),
			$urlGenerator,
			$ticks,
		) extends EtherpadHealthCheckService {
			/** @param list<float> $ticks */
			public function __construct(
				EtherpadClient $etherpadClient,
				PendingDeleteRetryService $pendingDeleteRetryService,
				IL10N $l10n,
				CookieDomainPolicy $cookieDomainPolicy,
				BaseUrlReachabilityCheck $baseUrlCheck,
				IURLGenerator $urlGenerator,
				private array $ticks,
			) {
				parent::__construct($etherpadClient, $pendingDeleteRetryService, $l10n, $cookieDomainPolicy, $baseUrlCheck, $urlGenerator);
			}

			protected function now(): float {
				return array_shift($this->ticks) ?? 100.1246;
			}
		};

		$result = $service->check($this->settings());

		$this->assertSame(125, $result->latencyMs);
	}



	/** @return iterable<string,array{0:string,1:string,2:string}> */
	public static function failureCaseProvider(): iterable {
		yield 'api key mode' => [
			'no or wrong API Key',
			'authenticationMethod',
			'etherpad_api_key',
		];
		yield 'dns failure' => [
			'Etherpad transport error: php_network_getaddresses: getaddrinfo for pad.example failed',
			'did not resolve',
			'etherpad_api_host',
		];
		yield 'connection refused' => [
			'Etherpad transport error: Connection refused',
			'Etherpad does not appear to be running',
			'etherpad_api_host',
		];
		yield 'timeout' => [
			'Etherpad transport error: stream_socket_client(): timed out',
			'Connection timed out',
			'etherpad_api_host',
		];
		yield 'tls handshake' => [
			'Etherpad transport error: SSL operation failed with code 1. OpenSSL Error',
			'TLS handshake failed',
			'etherpad_api_host',
		];
		yield 'http 401' => [
			'Etherpad API HTTP error (401)',
			'rejected the API key',
			'etherpad_api_key',
		];
		yield 'http 404' => [
			'Etherpad API HTTP error (404)',
			'API endpoint not found',
			'etherpad_api_host',
		];
		// Etherpad answered, so the address is right and something behind it
		// is not: no field to mark.
		yield 'http 502' => [
			'Etherpad API HTTP error (502)',
			'server error',
			'',
		];
		yield 'invalid json' => [
			'Invalid JSON response from Etherpad API.',
			'non-JSON',
			'',
		];
		yield 'unrecognised' => [
			'Etherpad API request failed: something new upstream',
			'',
			'',
		];
	}

	/**
	 * Hint and field come from one classification, so they are asserted from
	 * one table — two matchers over the same strings could hand out a correct
	 * hint with the wrong field.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('failureCaseProvider')]
	public function testCheckClassifiesAFailureOnce(string $clientMessage, string $expectedHintFragment, string $expectedField): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException(new EtherpadClientException($clientMessage));

		try {
			$this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class))->check($this->settings());
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertStringContainsString($clientMessage, $e->getMessage());
			if ($expectedHintFragment === '') {
				$this->assertStringNotContainsString('Hint:', $e->getMessage());
			} else {
				$this->assertStringContainsString($expectedHintFragment, $e->getMessage());
			}
			$this->assertSame($expectedField, $e->getField());
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
