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
use OCA\EtherpadNextcloud\Tests\Support\ScriptedClock;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;

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

		// Unrelated domains and no saved cookie domain: the API answers, but
		// the policy cannot derive one that spans both hosts.
		$result = $this->buildService($etherpad, $this->pendingCounts(0), 'https://cloud.example.test')
			->check($this->settings('https://pad.unrelated.test', true, ''));

		$this->assertNotNull($result->cookieDomain);
		$this->assertFalse($result->cookieDomain->isOk());
		$this->assertSame(CookieDomainDecision::REASON_NO_COMMON_PARENT, $result->cookieDomain->reason);
		$this->assertSame('cloud.example.test', $result->cookieDomain->nextcloudHost);
		$this->assertSame('pad.unrelated.test', $result->cookieDomain->etherpadHost);

	}

	public function testCheckSkipsTheCookieVerdictWhenProtectedPadsAreSubmittedAsDisabled(): void {
		$etherpad = $this->createMock(EtherpadClient::class);

		$result = $this->buildService($etherpad, $this->pendingCounts(0))
			->check($this->settings('https://pad.unrelated.test', false));

		$this->assertNull($result->cookieDomain);
	}



	/** With no separate API URL the address failure is about the base URL. */
	public function testTransportFailureFallsBackToTheBaseUrlFieldWhenNoApiUrlIsSet(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('assertApiKeyAccepted')->willThrowException(new EtherpadClientException('Etherpad transport error: connection refused'));

		$settings = new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad.example.test',
			'.example.test',
			'ep-api-0123456789abcdef',
			'ep-api-0123456789abcdef',
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
		string $configuredApiHost = 'https://pad-api.example.test',
		string $sameSiteSetting = 'lax',
		string $unrecognisedSameSite = '',
		array $trustedEmbedOrigins = [],
		string $nextcloudSameSite = '',
	): EtherpadHealthCheckService {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn($nextcloudUrl);
		// The stored API host. The session-cookie line compares it with the
		// submitted one, because a form being typed says nothing about what
		// pads are doing right now.
		$etherpad->method('getApiHost')->willReturn($configuredApiHost);
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
		$padSessions = $this->createMock(\OCA\EtherpadNextcloud\Service\PadSessionService::class);
		$padSessions->method('sameSiteMode')->willReturn(
			$sameSiteSetting === 'none'
				? \OCA\EtherpadNextcloud\Service\PadSessionService::SAME_SITE_NONE
				: \OCA\EtherpadNextcloud\Service\PadSessionService::SAME_SITE_LAX
		);
		$padSessions->method('unrecognisedSameSite')->willReturn($unrecognisedSameSite);
		$appConfig = $this->createMock(\OCA\EtherpadNextcloud\Service\AppConfigService::class);
		$appConfig->method('getTrustedEmbedOrigins')->willReturn($trustedEmbedOrigins);

		// There is no session in a unit test, so the runtime read of
		// Nextcloud's own cookie needs a seam to be exercised at all.
		return new class(
			$etherpad,
			$pending,
			$this->buildL10n(),
			new CookieDomainPolicy(),
			$this->baseUrlCheck(),
			$urlGenerator,
			$releasePolicy,
			$padSessions,
			$appConfig,
			$nextcloudSameSite,
		) extends EtherpadHealthCheckService {
			public function __construct(
				EtherpadClient $etherpadClient,
				PendingDeleteRetryService $pendingDeleteRetryService,
				IL10N $l10n,
				CookieDomainPolicy $cookieDomainPolicy,
				BaseUrlReachabilityCheck $baseUrlCheck,
				IURLGenerator $urlGenerator,
				\OCA\EtherpadNextcloud\Service\EtherpadReleasePolicy $releasePolicy,
				\OCA\EtherpadNextcloud\Service\PadSessionService $padSessionService,
				\OCA\EtherpadNextcloud\Service\AppConfigService $appConfigService,
				private string $nextcloudSameSite,
			) {
				parent::__construct($etherpadClient, $pendingDeleteRetryService, $l10n, $cookieDomainPolicy, $baseUrlCheck, $urlGenerator, $releasePolicy, $padSessionService, $appConfigService, new FixedClock());
			}

			protected function nextcloudSessionSameSite(): string {
				return $this->nextcloudSameSite;
			}
		};
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
		string $configuredApiHost = 'https://pad-api.example.test',
		string $sameSiteSetting = 'lax',
		string $unrecognisedSameSite = '',
		array $trustedEmbedOrigins = [],
		string $nextcloudSameSite = '',
	): HealthCheckItem {
		$result = $this->buildService(
			$etherpad,
			$this->pendingCounts(0),
			httpOnlyOverride: $override,
			knownRelease: $knownRelease,
			unrecognisedOverride: $unrecognisedOverride,
			configuredApiHost: $configuredApiHost,
			sameSiteSetting: $sameSiteSetting,
			unrecognisedSameSite: $unrecognisedSameSite,
			trustedEmbedOrigins: $trustedEmbedOrigins,
			nextcloudSameSite: $nextcloudSameSite,
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
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');

		$line = $this->sessionCookieLine($etherpad, knownRelease: '3.3.3');
		self::assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		self::assertStringContainsString('2.7.3', $line->detail);
		self::assertStringContainsString('3.3.3', $line->detail);
	}

	/** The admin test is as patient as the calls beside it. */
	public function testSessionCookieLineGivesThePadServerTheFullTimeout(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects(self::once())
			->method('detectReleaseVersion')
			->with(self::anything(), EtherpadClient::REQUEST_TIMEOUT_SECONDS)
			->willReturn('3.3.3');

		self::assertSame(HealthCheckItem::STATUS_OK, $this->sessionCookieLine($etherpad, knownRelease: '3.3.3')->status);
	}

	/** The case with no other signal at all: /health unreachable. */
	public function testSessionCookieLineIsSkippedWhenTheReleaseCannotBeRead(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
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
			'ep-api-0123456789abcdef',
			'ep-api-0123456789abcdef',
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
	 * Typing a new address and pressing Test is not a lockout. Pads are
	 * still going to the saved server, and comparing a probe of the typed
	 * address against what they are doing would read every planned
	 * migration as one.
	 */
	public function testSessionCookieLineDoesNotCallAnUnsavedAddressALockout(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');

		// Saved: a different server, which is still an Etherpad 3 as far as
		// the open path is concerned.
		$line = $this->sessionCookieLine(
			$etherpad,
			knownRelease: '3.3.3',
			configuredApiHost: 'https://old-api.example.test',
		);

		self::assertSame(HealthCheckItem::STATUS_OK, $line->status);
		self::assertStringContainsString('2.7.3', $line->label);
		self::assertStringContainsString('save', $line->detail);
	}

	/**
	 * DNS, a TLS mismatch, a 404 from a proxy that does not route /health
	 * and proxy auth are four different fixes. A fixed sentence points at
	 * none of them, and the class already knows this vocabulary.
	 */
	public function testSessionCookieLineCarriesTheReasonTheProbeFailed(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('detectReleaseVersion')
			->willThrowException(new EtherpadClientException('cURL error 6: Could not resolve host: pad.example.test'));

		$line = $this->sessionCookieLine($etherpad);
		self::assertSame(HealthCheckItem::STATUS_SKIPPED, $line->status);
		self::assertStringContainsString('Could not resolve host', $line->detail);
	}

	/**
	 * `SameSite=None` is the one setting here that hands the cookie to other
	 * sites, and it works only where Nextcloud authenticates without a
	 * cookie. Whether that holds is not this app's to decide — but
	 * Nextcloud's own session cookie says which half of the question the
	 * admin is in, so it is reported rather than assumed.
	 */
	public function testSessionCookieLineWarnsWhenTheCookieIsSentToOtherSites(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');

		$line = $this->sessionCookieLine($etherpad, sameSiteSetting: 'none');
		self::assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		self::assertStringContainsString('SameSite=None', $line->detail);
		self::assertStringContainsString('REMOTE_USER', $line->detail);
	}

	/**
	 * The panel shows a label only while the status is ok. Folding the note
	 * in makes the line a warning, so a passing line's text — the release,
	 * the cookie verdict — has to move into the detail or it is gone.
	 */
	public function testTheCrossSiteNoteKeepsWhatThePassingLineSaid(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('detectReleaseVersion')->willReturn('3.3.3');

		$line = $this->sessionCookieLine($etherpad, knownRelease: '3.3.3', sameSiteSetting: 'none');
		self::assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		self::assertStringContainsString('3.3.3', $line->detail);
		self::assertStringContainsString('SameSite=None', $line->detail);
	}

	/** The runtime read, in both directions. */
	public function testTheCrossSiteNoteNamesWhatNextcloudDoesWithItsOwnCookie(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');

		$named = $this->sessionCookieLine($etherpad, sameSiteSetting: 'none', nextcloudSameSite: 'lax');
		self::assertStringContainsString('Nextcloud sends its own session cookie as Lax', $named->detail);

		$silent = $this->sessionCookieLine($etherpad, sameSiteSetting: 'none', nextcloudSameSite: '');
		self::assertStringNotContainsString('sends its own session cookie', $silent->detail);
	}

	/**
	 * The configuration that breaks silently, and the one this check had no
	 * word for: an embed origin the session cookie does not reach, while the
	 * cookie stays same-site.
	 */
	public function testAnEmbedOriginOutsideTheCookieDomainIsNamed(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');
		$etherpad->method('getConfiguredOrigin')->willReturn('https://pad.example.test');

		$line = $this->sessionCookieLine($etherpad, trustedEmbedOrigins: ['https://partner.example.com']);
		self::assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		self::assertStringContainsString('partner.example.com', $line->detail);
	}

	/** One the cookie already reaches is certainly fine and stays quiet. */
	public function testAnEmbedOriginInsideTheCookieDomainIsNotNamed(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');
		$etherpad->method('getConfiguredOrigin')->willReturn('https://pad.example.test');

		$line = $this->sessionCookieLine($etherpad, trustedEmbedOrigins: ['https://portal.example.test']);
		self::assertSame(HealthCheckItem::STATUS_OK, $line->status);
	}

	/** A value that is neither lax nor none is said out loud, like its sibling. */
	public function testAnUnrecognisedSameSiteValueIsNamed(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');

		$line = $this->sessionCookieLine($etherpad, unrecognisedSameSite: 'strict');
		self::assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		self::assertStringContainsString('strict', $line->detail);
	}

	/**
	 * There is one session-cookie slot. A note about how far the cookie
	 * travels must not push off the line saying that no protected pad opens
	 * at all — which is what `yes` below Etherpad 3.0 means.
	 */
	public function testTheCrossSiteNoteDoesNotHideALockout(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('detectReleaseVersion')->willReturn('2.7.3');

		$line = $this->sessionCookieLine($etherpad, 'yes', sameSiteSetting: 'none');
		self::assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		// The lockout, still there.
		self::assertStringContainsString('by hand', $line->detail);
		self::assertStringContainsString('2.7.3', $line->detail);
		// And the note beside it.
		self::assertStringContainsString('SameSite=None', $line->detail);
	}

	/** Same for a value nobody meant: the ignored setting still gets named. */
	public function testTheCrossSiteNoteDoesNotHideAnUnrecognisedOverride(): void {
		$etherpad = $this->createMock(EtherpadClient::class);

		$line = $this->sessionCookieLine($etherpad, unrecognisedOverride: 'true', sameSiteSetting: 'none');
		self::assertStringContainsString('true', $line->detail);
		self::assertStringContainsString('SameSite=None', $line->detail);
	}

	/**
	 * The dangerous half of the override shouts, and says what the server
	 * actually is so somebody who reached for it during an outage can see
	 * whether they may put it back.
	 */
	public function testSessionCookieLineWarnsWhenHttpOnlyIsForcedOn(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
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
			->method('assertApiKeyAccepted')
			->with('https://pad-api.example.test', 'ep-api-0123456789abcdef', '1.3.0');
		$pending = $this->createMock(PendingDeleteRetryService::class);
		$pending->expects($this->once())->method('countPendingDeletes')->willReturn(3);

		$result = ($this->buildService($etherpad, $pending))->check($this->settings());

		$this->assertSame(3, $result->pendingDeleteCount);
		$this->assertSame('https://pad-api.example.test/api/1.3.0/checkToken', $result->target);
	}


	public function testCheckRoundsLatencyMilliseconds(): void {
		$etherpad = $this->createMock(EtherpadClient::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.test');
		$service = new EtherpadHealthCheckService(
			$etherpad,
			$this->pendingCounts(0),
			$this->buildL10n(),
			new CookieDomainPolicy(),
			$this->baseUrlCheck(),
			$urlGenerator,
			$this->createMock(\OCA\EtherpadNextcloud\Service\EtherpadReleasePolicy::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadSessionService::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\AppConfigService::class),
			new ScriptedClock([100.0000, 100.1246]),
		);

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
		$etherpad->method('assertApiKeyAccepted')->willThrowException(
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
			'api_key_mode',
		];
		yield 'dns failure' => [
			'Etherpad transport error: php_network_getaddresses: getaddrinfo for pad.example failed',
			'did not resolve',
			'etherpad_api_host',
			'dns',
		];
		yield 'connection refused' => [
			'Etherpad transport error: Connection refused',
			'Etherpad does not appear to be running',
			'etherpad_api_host',
			'connection_refused',
		];
		yield 'timeout' => [
			'Etherpad transport error: stream_socket_client(): timed out',
			'Connection timed out',
			'etherpad_api_host',
			'timeout',
		];
		yield 'tls handshake' => [
			'Etherpad transport error: SSL operation failed with code 1. OpenSSL Error',
			'TLS handshake failed',
			'etherpad_api_host',
			'tls',
		];
		yield 'http 401' => [
			'Etherpad API HTTP error (401)',
			'rejected the API key',
			'etherpad_api_key',
			'api_key_rejected',
		];
		yield 'http 404' => [
			'Etherpad API HTTP error (404)',
			'API endpoint not found',
			'etherpad_api_host',
			'api_not_found',
		];
		// Etherpad answered, so the address is right and something behind it
		// is not: no field to mark.
		yield 'http 502' => [
			'Etherpad API HTTP error (502)',
			'server error',
			'',
			'server_error',
		];
		yield 'invalid json' => [
			'Invalid JSON response from Etherpad API.',
			'non-JSON',
			'',
			'invalid_json',
		];
		// No advice to give, but still about the key: the field must survive.
		yield 'other api key trouble' => [
			'Etherpad API request failed: API key file could not be read',
			'',
			'etherpad_api_key',
			'api_key_other',
		];
		yield 'unrecognised' => [
			'Etherpad API request failed: something new upstream',
			'',
			'',
			'unknown',
		];
	}

	/**
	 * The reason alone cannot separate two failures the matcher does not
	 * recognise, so the cause carries the wording - untranslated, and with
	 * the one secret in scope taken out of it.
	 */
	public function testUnknownFailuresCarryDistinguishableCauses(): void {
		$causes = [];
		foreach (['something new upstream', 'something else entirely'] as $message) {
			$etherpad = $this->createMock(EtherpadClient::class);
			$etherpad->method('assertApiKeyAccepted')
				->willThrowException(new EtherpadClientException('Etherpad API request failed: ' . $message));
			try {
				$this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class))
					->check($this->settings());
				$this->fail('Expected health check exception.');
			} catch (AdminHealthCheckException $e) {
				$this->assertSame('unknown', $e->getReason());
				$causes[] = $e->getCause();
			}
		}

		$this->assertNotSame($causes[0], $causes[1]);
		$this->assertStringContainsString('something new upstream', $causes[0]);
	}

	/**
	 * Redaction has to happen before the text is cut: a key that straddles
	 * the cut is no longer there to be matched, and its prefix travels on.
	 */
	public function testAShortApiKeyIsRedactedToo(): void {
		// The validator accepts any non-empty key, so a short one is a real
		// configuration - and just as much a credential as a long one.
		$secret = 'k7xq2pd';
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('assertApiKeyAccepted')->willThrowException(
			new EtherpadClientException('Etherpad transport error: rejected apikey=' . $secret),
		);

		try {
			$this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class))
				->check($this->settings('https://pad.example.test', true, '.example.test', $secret));
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertStringNotContainsString($secret, $e->getCause());
		}
	}

	public function testAKeyStraddlingTheLengthCutIsStillRedacted(): void {
		$secret = 'abcdefghijklmnopqrstuvwxyz0123';
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('assertApiKeyAccepted')->willThrowException(
			new EtherpadClientException(str_repeat('x', 145) . ' apikey=' . $secret . ' tail'),
		);

		try {
			$this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class))
				->check($this->settings('https://pad.example.test', true, '.example.test', $secret));
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			// A prefix, not the whole value: the cut is what makes the whole
			// value absent, so looking for it would pass against the defect.
			$this->assertStringNotContainsString(substr($secret, 0, 6), $e->getCause());
			$this->assertStringNotContainsString(substr($secret, 0, 6), $e->getMessage());
		}
	}

	public function testTheCauseDoesNotCarryTheApiKey(): void {
		$secret = 'super-secret-etherpad-key-987';
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('assertApiKeyAccepted')->willThrowException(
			new EtherpadClientException('Etherpad transport error: rejected apikey=' . $secret),
		);

		try {
			$this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class))
				->check($this->settings('https://pad.example.test', true, '.example.test', $secret));
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertStringNotContainsString($secret, $e->getCause());
			$this->assertStringContainsString('***', $e->getCause());
		}
	}

	/**
	 * Hint, field and reason come from one classification, so they are
	 * asserted from one table — separate matchers over the same strings could
	 * hand out a correct hint with the wrong field or reason.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('failureCaseProvider')]
	public function testCheckClassifiesAFailureOnce(string $clientMessage, string $expectedHintFragment, string $expectedField, string $expectedReason): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('assertApiKeyAccepted')->willThrowException(new EtherpadClientException($clientMessage));

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
			// The reason is what the log gets, and the only machine-readable
			// record of why: a thrower that drops it would leave no trace.
			$this->assertSame($expectedReason, $e->getReason());
		}
	}

	public function testCheckMatchesHintAgainstWrappedPreviousException(): void {
		// EtherpadClient::apiCall wraps transport-level failures as
		// 'Etherpad API request failed: <method>' with the real cause as
		// previous. The hint matcher must read through the chain.
		$inner = new EtherpadClientException('Etherpad transport error: php_network_getaddresses: getaddrinfo for pad.does-not-exist.invalid failed');
		$wrapped = new EtherpadClientException('Etherpad API request failed: checkToken', 0, $inner);

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('assertApiKeyAccepted')->willThrowException($wrapped);

		try {
			($this->buildService($etherpad, $this->createMock(PendingDeleteRetryService::class)))
				->check($this->settings());
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertStringContainsString('Etherpad API request failed: checkToken', $e->getMessage());
			$this->assertStringContainsString('getaddrinfo', $e->getMessage());
			$this->assertStringContainsString('did not resolve', $e->getMessage());
		}
	}


	private function settings(
		string $etherpadHost = 'https://pad.example.test',
		bool $enableProtectedPads = true,
		string $cookieDomain = '.example.test',
		string $apiKey = 'ep-api-0123456789abcdef',
	): ValidatedAdminSettings {
		return new ValidatedAdminSettings(
			$etherpadHost,
			'https://pad-api.example.test',
			$cookieDomain,
			$apiKey,
			$apiKey,
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
