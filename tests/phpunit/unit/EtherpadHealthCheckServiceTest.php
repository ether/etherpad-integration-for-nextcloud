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
		$this->assertSame(['api', 'api_key', 'base_url', 'session_cookie'], array_keys($byId));
		$this->assertSame(HealthCheckItem::STATUS_OK, $byId['api']->status);
	}


	private function buildService(
		EtherpadClient $etherpad,
		PendingDeleteRetryService $pending,
		string $nextcloudUrl = 'https://cloud.example.test',
		string $httpOnlyOverride = 'auto',
		string $knownRelease = '',
		string $unrecognisedOverride = '',
	): EtherpadHealthCheckService {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn($nextcloudUrl);
		// What the open path is doing, which is the thing this line reports.
		// It answers from a cache, so it can disagree with the server.
		$releasePolicy = $this->createMock(\OCA\EtherpadNextcloud\Service\EtherpadReleasePolicy::class);
		$releasePolicy->method('overrideMode')->willReturn($httpOnlyOverride);
		$releasePolicy->method('unrecognisedOverride')->willReturn($unrecognisedOverride);
		// Read, never resolved: resolving would refresh the cache against
		// the stored host and write to app config from a form that may not
		// be saved.
		$releasePolicy->expects(self::never())->method('supportsHttpOnlySessionCookie');
		$releasePolicy->method('knownRelease')->willReturn($knownRelease);
		return new EtherpadHealthCheckService(
			$etherpad,
			$pending,
			$this->buildL10n(),
			new CookieDomainPolicy(),
			$this->baseUrlCheck(),
			$urlGenerator,
			$releasePolicy,
		);
	}

	/**
	 * The release decides whether the session cookie may be kept from
	 * JavaScript, it is discovered rather than configured, and it lives in
	 * an app value with no field of its own. Without a line here, a wrong
	 * answer shows up only as "no protected pad opens".
	 */
	private function sessionCookieLine(
		EtherpadClient $etherpad,
		string $override = 'auto',
		string $knownRelease = '',
		string $unrecognisedOverride = '',
	): HealthCheckItem {
		$result = $this->buildService(
			$etherpad,
			$this->pendingCounts(0),
			httpOnlyOverride: $override,
			knownRelease: $knownRelease,
			unrecognisedOverride: $unrecognisedOverride,
		)->check($this->settings());
		foreach ($result->checks as $item) {
			if ($item->id === 'session_cookie') {
				return $item;
			}
		}
		self::fail('no session_cookie line in the health check');
	}

	public function testSessionCookieLineNamesTheReleaseThatAllowsHttpOnly(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);
		$etherpad->method('detectReleaseVersion')->willReturn('3.3.3');

		$line = $this->sessionCookieLine($etherpad, knownRelease: '3.3.3');
		self::assertSame(HealthCheckItem::STATUS_OK, $line->status);
		// The label, not the detail: the admin panel shows only the label
		// for a passing line, so that is where the release has to be.
		self::assertStringContainsString('3.3.3', $line->label);
		self::assertSame('etherpad_session_cookie', $line->field);
	}

	public function testSessionCookieLineNamesTheReleaseThatNeedsAReadableCookie(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');

		$line = $this->sessionCookieLine($etherpad, knownRelease: '2.7.3');
		self::assertSame(HealthCheckItem::STATUS_OK, $line->status);
		self::assertStringContainsString('2.7.3', $line->label);
		self::assertStringContainsString('readable', $line->label);
	}

	/**
	 * The moment this line exists for: the open path answers from a cached
	 * release, so right after a downgrade it is still sending an HttpOnly
	 * cookie to a pad server that cannot read it. Reporting what this host
	 * says now, and calling it OK, would state the opposite of the lockout
	 * the admin came here to explain.
	 */
	public function testSessionCookieLineWarnsWhenTheCookieDisagreesWithTheServer(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');

		$line = $this->sessionCookieLine($etherpad, knownRelease: '3.3.3');
		self::assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		self::assertStringContainsString('2.7.3', $line->detail);
		self::assertStringContainsString('3.3.3', $line->detail);
	}

	/** The admin test is as patient as the calls beside it. */
	public function testSessionCookieLineGivesThePadServerTheFullTimeout(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);
		$etherpad->expects(self::once())
			->method('detectReleaseVersion')
			->with(self::anything(), EtherpadClient::REQUEST_TIMEOUT_SECONDS)
			->willReturn('3.3.3');

		self::assertSame(HealthCheckItem::STATUS_OK, $this->sessionCookieLine($etherpad, knownRelease: '3.3.3')->status);
	}

	/** The case with no other signal at all: /health unreachable. */
	public function testSessionCookieLineIsSkippedWhenTheReleaseCannotBeRead(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);
		$etherpad->method('detectReleaseVersion')
			->willThrowException(new EtherpadClientException('Connection timed out'));

		// Not a warning: the cookie stays readable, which is what every
		// Etherpad before 3.0 needs anyway. A pad server without /health, or
		// a proxy that routes /api and not /health, works and merely misses
		// a hardening.
		$line = $this->sessionCookieLine($etherpad);
		self::assertSame(HealthCheckItem::STATUS_SKIPPED, $line->status);
		// Its own slot. Sharing a field with the API lines means losing to
		// them: the panel keeps the highest severity per field, and a
		// passing `api` outranks a skip — so on the very deployment this
		// branch describes, the line would render nowhere.
		self::assertSame('etherpad_session_cookie', $line->field);
	}

	private function settingsWithoutSeparateApiHost(): ValidatedAdminSettings {
		return new ValidatedAdminSettings(
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
			true,
		);
	}

	/**
	 * The dangerous half of the override shouts, and says what the server
	 * actually is so somebody who reached for it during an outage can see
	 * whether they may put it back.
	 */
	public function testSessionCookieLineWarnsWhenHttpOnlyIsForcedOn(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');

		$line = $this->sessionCookieLine($etherpad, 'yes');
		self::assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		self::assertStringContainsString('by hand', $line->detail);
		self::assertStringContainsString('2.7.3', $line->detail);
	}

	/**
	 * The safe half does not. It gives up a hardening and is what this app
	 * did before any of this — warning on it teaches an admin to ignore the
	 * line that would have told them about the other one.
	 */
	public function testSessionCookieLineDoesNotWarnWhenHttpOnlyIsForcedOff(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);
		$etherpad->method('detectReleaseVersion')->willReturn('3.3.3');

		$line = $this->sessionCookieLine($etherpad, 'no');
		self::assertSame(HealthCheckItem::STATUS_OK, $line->status);
		self::assertStringContainsString('3.3.3', $line->detail);
	}

	/**
	 * `--value=true`, `--value=1`, `--value=off`: the words an admin reaches
	 * for while pads are down. All of them silently mean auto, and the line
	 * would otherwise confirm the automatic behaviour as fine.
	 */
	public function testSessionCookieLineNamesAnUnrecognisedOverride(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);

		$line = $this->sessionCookieLine($etherpad, unrecognisedOverride: 'true');
		self::assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		self::assertStringContainsString('true', $line->detail);
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
			$this->createMock(\OCA\EtherpadNextcloud\Service\EtherpadReleasePolicy::class),
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
				\OCA\EtherpadNextcloud\Service\EtherpadReleasePolicy $releasePolicy,
				private array $ticks,
			) {
				parent::__construct($etherpadClient, $pendingDeleteRetryService, $l10n, $cookieDomainPolicy, $baseUrlCheck, $urlGenerator, $releasePolicy);
			}

			protected function now(): float {
				return array_shift($this->ticks) ?? 100.1246;
			}
		};

		$result = $service->check($this->settings());

		$this->assertSame(125, $result->latencyMs);
	}



	/**
	 * A transport failure can carry a long tail of internal hostnames and
	 * addresses. The admin needs the actionable part, not all of it — but the
	 * classification has to see the whole thing, or a keyword behind the cut
	 * would take the hint and the field with it.
	 */
	public function testLongFailuresAreShortenedWithoutLosingTheClassification(): void {
		$tail = str_repeat('internal-host.example.invalid ', 20);
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException(
			new EtherpadClientException('Etherpad transport error: ' . $tail . 'Connection refused')
		);

		try {
			$this->buildService($etherpad, $this->pendingCounts(0))->check($this->settings());
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertStringContainsString('…', $e->getMessage());
			$this->assertLessThan(strlen($tail), strlen($e->getMessage()));
			// Classified from the full text, so hint and field survive.
			$this->assertStringContainsString('Etherpad does not appear to be running', $e->getMessage());
			$this->assertSame('etherpad_api_host', $e->getField());
		}
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
		// No advice to give, but still about the key: the field must survive.
		yield 'other api key trouble' => [
			'Etherpad API request failed: API key file could not be read',
			'',
			'etherpad_api_key',
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
