<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PadSessionServiceTest extends TestCase {
	/**
	 * The cookie domain now depends on the Nextcloud host as well, so every
	 * case runs against a Nextcloud that shares a parent with the Etherpad
	 * host used in the fixtures.
	 */
	private function buildService(
		EtherpadClient $etherpadClient,
		IConfig $config,
		string $nextcloudUrl = 'https://cloud.example.test',
		?string $incomingSessionCookie = null,
	): PadSessionService {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn($nextcloudUrl);
		$request = $this->createMock(IRequest::class);
		$request->method('getCookie')->with('sessionID')->willReturn($incomingSessionCookie);
		return new PadSessionService($etherpadClient, $config, $urlGenerator, new CookieDomainPolicy(), $request);
	}

	/**
	 * Each protected pad is its own Etherpad group, and a session grants
	 * access to one group. Etherpad holds the author's sessions and knows
	 * which group each belongs to, so that list — not the incoming cookie —
	 * decides what the cookie should say.
	 *
	 * @param array<string,array{groupID:string,validUntil:int}>|null $sessions null makes the listing fail
	 */
	private function openContextCookieValue(
		?array $sessions,
		?string $incoming = null,
		string $groupId = 'g.ABCDEFGHIJKLMNOP',
		?EtherpadClient $client = null,
	): string {
		$etherpadClient = $client ?? $this->createMock(EtherpadClient::class);
		if ($client === null) {
			$etherpadClient->method('createSession')->willReturn('s.new');
		}
		if ($sessions === null) {
			$etherpadClient->method('listSessionsOfAuthor')
				->willThrowException(new EtherpadClientException('unavailable'));
		} else {
			$etherpadClient->method('listSessionsOfAuthor')->willReturn($sessions);
		}
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturn('a.author');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		$config = $this->createMock(IConfig::class);
		$service = $this->buildService($etherpadClient, $config, 'https://cloud.example.test', $incoming);

		return $service->createProtectedOpenContext('admin', 'Admin', $groupId . '$pad-1')['cookie']['value'];
	}

	public function testCarriesTheAuthorsOtherLivingSessions(): void {
		$value = $this->openContextCookieValue([
			's.other' => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => time() + 3600],
		]);

		$this->assertSame('s.new,s.other', $value);
	}

	/**
	 * The bug a cookie-only list could not fix: every open minted a session
	 * even for a pad already open, so ten entries meant ten opens. Opening
	 * one pad repeatedly pushed the other out.
	 */
	public function testReusesTheLivingSessionOfTheGroupBeingOpened(): void {
		$existing = time() + 3600;
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createSession');

		$value = $this->openContextCookieValue(
			[
				's.same' => ['groupID' => 'g.ABCDEFGHIJKLMNOP', 'validUntil' => $existing],
				's.other' => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => time() + 60],
			],
			null,
			'g.ABCDEFGHIJKLMNOP',
			$etherpadClient,
		);

		$this->assertSame('s.same,s.other', $value);
	}

	public function testMintsAFreshSessionWhenTheGroupsSessionIsAboutToExpire(): void {
		$value = $this->openContextCookieValue([
			's.nearly' => ['groupID' => 'g.ABCDEFGHIJKLMNOP', 'validUntil' => time() + 60],
		]);

		$this->assertSame('s.new', $value);
	}

	public function testDropsSessionsThatHaveExpired(): void {
		$value = $this->openContextCookieValue([
			's.dead' => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => time() - 10],
		]);

		$this->assertSame('s.new', $value);
	}

	public function testKeepsTheCookieBoundedAndDropsWhatExpiresSoonest(): void {
		$sessions = [];
		foreach (range(1, 30) as $i) {
			$sessions['s.g' . $i] = ['groupID' => 'g.GROUP' . str_pad((string)$i, 11, '0'), 'validUntil' => time() + 600 + $i];
		}

		$value = $this->openContextCookieValue($sessions);
		$ids = explode(',', $value);

		$this->assertCount(25, $ids);
		$this->assertSame('s.new', $ids[0]);
		// Longest-lived first after the pad being opened, soonest dropped.
		$this->assertSame('s.g30', $ids[1]);
		$this->assertNotContains('s.g1', $ids);
		// Still comfortably inside what a cookie may carry.
		$this->assertLessThan(2000, strlen($value));
	}

	/**
	 * When Etherpad cannot answer, the open still happens — with a fresh
	 * session merged into what the browser sent, which is all this had
	 * before the listing existed.
	 */
	public function testFallsBackToTheBrowsersCookieWhenTheListingFails(): void {
		$this->assertSame('s.new,s.old', $this->openContextCookieValue(null, 's.old'));
	}

	public function testTheFallbackCarriesNothingOverThatIsNotASessionId(): void {
		$this->assertSame('s.new', $this->openContextCookieValue(null, 'nonsense; HttpOnly,../etc,s.'));
	}

	public function testTheFallbackAcceptsTheQuotedCookieForm(): void {
		$this->assertSame('s.new,s.one,s.two', $this->openContextCookieValue(null, '"s.one, s.two"'));
	}

	/**
	 * A renamed user still reaches Etherpad: the cache answers "unchanged"
	 * only when the stored name matches the one being opened with.
	 */
	public function testCreateProtectedOpenContextSyncsWhenTheDisplayNameChanged(): void {
		$uid = 'alice';
		$padId = 'g.ABCDEFGHIJKLMNOP$pad-1';

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createAuthorIfNotExistsFor')
			->with('nc:' . $uid, 'Alice Renamed')
			->willReturn('a.cached');
		$etherpadClient->method('createSession')->willReturn('s.session');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['etherpad_nextcloud', 'etherpad_cookie_domain', '', ''],
			['etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no', 'no'],
			['etherpad_nextcloud', 'etherpad_host', '', 'https://pad.example.test'],
		]);
		$config->method('getUserValue')->willReturnMap([
			[$uid, 'etherpad_nextcloud', 'etherpad_author_id', '', 'a.cached'],
			[$uid, 'etherpad_nextcloud', 'etherpad_author_display_name', '', 'Alice Example'],
		]);
		$config->expects($this->once())
			->method('setUserValue')
			->with($uid, 'etherpad_nextcloud', 'etherpad_author_display_name', 'Alice Renamed');

		$service = $this->buildService($etherpadClient, $config);

		$service->createProtectedOpenContext($uid, 'Alice Renamed', $padId);
	}

	public function testExtractGroupIdReturnsGroupPrefix(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$config = $this->createMock(IConfig::class);
		$service = $this->buildService($etherpadClient, $config);

		$groupId = $service->extractGroupId('g.ABCDEFGHIJKLMNOP$my-pad-name');
		$this->assertSame('g.ABCDEFGHIJKLMNOP', $groupId);
	}

	public function testExtractGroupIdRejectsInvalidId(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$config = $this->createMock(IConfig::class);
		$service = $this->buildService($etherpadClient, $config);

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Protected pad ID is invalid');
		$service->extractGroupId('not-a-group-pad-id');
	}

	public function testCreateProtectedOpenContextUsesUidAsFallbackDisplayNameAndMinTtl(): void {
		$uid = 'admin';
		$padId = 'g.ABCDEFGHIJKLMNOP$pad-1';
		$groupId = 'g.ABCDEFGHIJKLMNOP';
		$authorId = 'a.test-author';
		$sessionId = 's.test-session';
		$padUrl = 'https://pad.example.test/p/' . rawurlencode($padId);
		$before = time();

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createAuthorIfNotExistsFor')
			->with('nc:' . $uid, $uid)
			->willReturn($authorId);
		$etherpadClient->expects($this->once())
			->method('createSession')
			->with(
				$groupId,
				$authorId,
				$this->callback(static function (int $validUntil) use ($before): bool {
					// TTL is clamped to at least 60 seconds.
					return $validUntil >= ($before + 60);
				})
			)
			->willReturn($sessionId);
		$etherpadClient->expects($this->once())
			->method('buildPadUrl')
			->with($padId)
			->willReturn($padUrl);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnMap([
				['etherpad_nextcloud', 'etherpad_cookie_domain', '', ''],
				['etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no', 'no'],
				['etherpad_nextcloud', 'etherpad_host', '', 'https://pad.example.test'],
			]);

		$service = $this->buildService($etherpadClient, $config);
		$result = $service->createProtectedOpenContext($uid, '   ', $padId, 10);
		$resultUrl = $result['url'];

		$this->assertSame($padUrl, $resultUrl);
		$this->assertSame('sessionID', $result['cookie']['name']);
		$this->assertSame($sessionId, $result['cookie']['value']);
		$this->assertSame('.example.test', $result['cookie']['domain']);
		$this->assertSame('None', $result['cookie']['same_site']);
		$this->assertTrue($result['cookie']['secure']);
	}

	public function testCreateProtectedOpenContextUsesExplicitCookieDomainOnly(): void {
		$uid = 'admin';
		$padId = 'g.ABCDEFGHIJKLMNOP$pad-1';

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturn('a.test-author');
		$etherpadClient->method('createSession')->willReturn('s.test-session');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . rawurlencode($padId));

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnMap([
				['etherpad_nextcloud', 'etherpad_cookie_domain', '', '.example.test'],
				['etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no', 'yes'],
				['etherpad_nextcloud', 'etherpad_host', '', 'https://pad.example.test'],
			]);

		$service = $this->buildService($etherpadClient, $config);
		$result = $service->createProtectedOpenContext($uid, 'Admin', $padId);

		$this->assertSame('.example.test', $result['cookie']['domain']);
	}

	public function testCreateProtectedOpenContextRespectsExplicitEmptyCookieDomain(): void {
		$uid = 'admin';
		$padId = 'g.ABCDEFGHIJKLMNOP$pad-1';

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturn('a.test-author');
		$etherpadClient->method('createSession')->willReturn('s.test-session');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . rawurlencode($padId));

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnMap([
				['etherpad_nextcloud', 'etherpad_cookie_domain', '', ''],
				['etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no', 'yes'],
				['etherpad_nextcloud', 'etherpad_host', '', 'https://pad.example.test'],
			]);

		$service = $this->buildService($etherpadClient, $config);
		$result = $service->createProtectedOpenContext($uid, 'Admin', $padId);

		$this->assertSame('', $result['cookie']['domain']);
	}

	public function testBuildSetCookieHeaderIncludesExpectedAttributes(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$config = $this->createMock(IConfig::class);
		$service = $this->buildService($etherpadClient, $config);

		$header = $service->buildSetCookieHeader([
			'name' => 'sessionID',
			'value' => 's.abc123',
			'expires' => time() + 3600,
			'path' => '/',
			'domain' => '.example.test',
			'secure' => true,
			'http_only' => false,
			'same_site' => 'None',
		]);

		$this->assertStringContainsString('sessionID=s.abc123', $header);
		$this->assertStringContainsString('Expires=', $header);
		$this->assertStringContainsString('Max-Age=', $header);
		$this->assertStringContainsString('Path=/', $header);
		$this->assertStringContainsString('Domain=.example.test', $header);
		$this->assertStringContainsString('Secure', $header);
		$this->assertStringContainsString('SameSite=None', $header);
		$this->assertStringNotContainsString("\n", $header);
		$this->assertStringNotContainsString("\r", $header);
	}

	/**
	 * The author lookup runs even when the stored name matches: it is what
	 * keeps Etherpad's copy of the name in step with Nextcloud's, and
	 * nothing else repairs a name that drifted on the Etherpad side.
	 */
	public function testCreateProtectedOpenContextRefreshesTheAuthorNameOnEveryOpen(): void {
		$uid = 'alice';
		$displayName = 'Alice Example';
		$padId = 'g.ABCDEFGHIJKLMNOP$pad-1';
		$groupId = 'g.ABCDEFGHIJKLMNOP';
		$authorId = 'a.cached';
		$sessionId = 's.cached';
		$padUrl = 'https://pad.example.test/p/' . rawurlencode($padId);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createAuthorIfNotExistsFor')
			->with('nc:' . $uid, $displayName)
			->willReturn($authorId);
		$etherpadClient->method('listSessionsOfAuthor')->willReturn([]);
		$etherpadClient->expects($this->once())
			->method('createSession')
			->with($groupId, $authorId, $this->isType('int'))
			->willReturn($sessionId);
		$etherpadClient->expects($this->once())
			->method('buildPadUrl')
			->with($padId)
			->willReturn($padUrl);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnMap([
				['etherpad_nextcloud', 'etherpad_cookie_domain', '', ''],
				['etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no', 'no'],
				['etherpad_nextcloud', 'etherpad_host', '', 'https://pad.example.test'],
			]);
		$config->method('getUserValue')
			->willReturnMap([
				[$uid, 'etherpad_nextcloud', 'etherpad_author_id', '', $authorId],
				[$uid, 'etherpad_nextcloud', 'etherpad_author_display_name', '', $displayName],
			]);
		$config->expects($this->never())->method('setUserValue');
		$config->expects($this->never())->method('deleteUserValue');

		$service = $this->buildService($etherpadClient, $config);
		$result = $service->createProtectedOpenContext($uid, $displayName, $padId);

		$this->assertSame($padUrl, $result['url']);
		$this->assertSame($sessionId, $result['cookie']['value']);
	}

	public function testCreateProtectedOpenContextSyncsChangedDisplayNameForCachedAuthor(): void {
		$uid = 'alice';
		$displayName = 'Alice Updated';
		$padId = 'g.ABCDEFGHIJKLMNOP$pad-1';
		$groupId = 'g.ABCDEFGHIJKLMNOP';
		$authorId = 'a.cached';
		$sessionId = 's.cached';

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createAuthorIfNotExistsFor')
			->with('nc:' . $uid, $displayName)
			->willReturn($authorId);
		$etherpadClient->expects($this->once())
			->method('createSession')
			->with($groupId, $authorId, $this->isType('int'))
			->willReturn($sessionId);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . rawurlencode($padId));

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnMap([
				['etherpad_nextcloud', 'etherpad_cookie_domain', '', ''],
				['etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no', 'no'],
				['etherpad_nextcloud', 'etherpad_host', '', 'https://pad.example.test'],
			]);
		$config->method('getUserValue')
			->willReturnMap([
				[$uid, 'etherpad_nextcloud', 'etherpad_author_id', '', $authorId],
				[$uid, 'etherpad_nextcloud', 'etherpad_author_display_name', '', 'Alice Old'],
			]);
		$config->expects($this->once())
			->method('setUserValue')
			->with($uid, 'etherpad_nextcloud', 'etherpad_author_display_name', $displayName);

		$service = $this->buildService($etherpadClient, $config);
		$service->createProtectedOpenContext($uid, $displayName, $padId);
	}

	public function testCreateProtectedOpenContextFallsBackToBootstrapWhenCachedAuthorFails(): void {
		$uid = 'alice';
		$displayName = 'Alice Example';
		$padId = 'g.ABCDEFGHIJKLMNOP$pad-1';
		$groupId = 'g.ABCDEFGHIJKLMNOP';
		$cachedAuthorId = 'a.cached';
		$freshAuthorId = 'a.fresh';
		$sessionId = 's.fresh';

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->exactly(2))
			->method('createAuthorIfNotExistsFor')
			->willReturnCallback(static function (string $authorMapper, string $name) use ($uid, $displayName, $cachedAuthorId, $freshAuthorId): string {
				static $call = 0;
				$call++;
				TestCase::assertSame('nc:' . $uid, $authorMapper);
				TestCase::assertSame($displayName, $name);
				return $call === 1 ? $cachedAuthorId : $freshAuthorId;
			});
		$etherpadClient->method('listSessionsOfAuthor')->willReturn([]);
		$etherpadClient->expects($this->exactly(2))
			->method('createSession')
			->willReturnCallback(static function (string $actualGroupId, string $actualAuthorId, int $validUntil) use ($groupId, $cachedAuthorId, $freshAuthorId, $sessionId): string {
				static $call = 0;
				$call++;
				TestCase::assertSame($groupId, $actualGroupId);
				TestCase::assertIsInt($validUntil);
				if ($call === 1) {
					TestCase::assertSame($cachedAuthorId, $actualAuthorId);
					throw new EtherpadClientException('cached author invalid');
				}

				TestCase::assertSame($freshAuthorId, $actualAuthorId);
				return $sessionId;
			});
		$etherpadClient->expects($this->once())
			->method('buildPadUrl')
			->willReturn('https://pad.example.test/p/' . rawurlencode($padId));

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnMap([
				['etherpad_nextcloud', 'etherpad_cookie_domain', '', ''],
				['etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no', 'no'],
				['etherpad_nextcloud', 'etherpad_host', '', 'https://pad.example.test'],
			]);
		$config->method('getUserValue')
			->willReturnMap([
				[$uid, 'etherpad_nextcloud', 'etherpad_author_id', '', $cachedAuthorId],
				[$uid, 'etherpad_nextcloud', 'etherpad_author_display_name', '', $displayName],
			]);
		$config->expects($this->exactly(2))
			->method('deleteUserValue')
			->willReturnCallback(static function (string $actualUid, string $appName, string $key) use ($uid): void {
				static $call = 0;
				$call++;
				TestCase::assertSame($uid, $actualUid);
				TestCase::assertSame('etherpad_nextcloud', $appName);
				if ($call === 1) {
					TestCase::assertSame('etherpad_author_id', $key);
					return;
				}

				TestCase::assertSame('etherpad_author_display_name', $key);
			});
		$config->expects($this->exactly(2))
			->method('setUserValue')
			->willReturnCallback(static function (string $actualUid, string $appName, string $key, string $value) use ($uid, $freshAuthorId, $displayName): void {
				static $call = 0;
				$call++;
				TestCase::assertSame($uid, $actualUid);
				TestCase::assertSame('etherpad_nextcloud', $appName);
				if ($call === 1) {
					TestCase::assertSame('etherpad_author_id', $key);
					TestCase::assertSame($freshAuthorId, $value);
					return;
				}

				TestCase::assertSame('etherpad_author_display_name', $key);
				TestCase::assertSame($displayName, $value);
			});

		$service = $this->buildService($etherpadClient, $config);
		$result = $service->createProtectedOpenContext($uid, $displayName, $padId);

		$this->assertSame($sessionId, $result['cookie']['value']);
	}

	public function testCreateProtectedOpenContextDoesNotPersistPublicShareAuthorState(): void {
		$uid = 'public-share:token';
		$displayName = 'Public Share';
		$padId = 'g.ABCDEFGHIJKLMNOP$pad-1';
		$authorId = 'a.public';
		$sessionId = 's.public';

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createAuthorIfNotExistsFor')
			->with('nc:' . $uid, $displayName)
			->willReturn($authorId);
		$etherpadClient->expects($this->once())
			->method('createSession')
			->willReturn($sessionId);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . rawurlencode($padId));

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnMap([
				['etherpad_nextcloud', 'etherpad_cookie_domain', '', ''],
				['etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no', 'no'],
				['etherpad_nextcloud', 'etherpad_host', '', 'https://pad.example.test'],
			]);
		$config->expects($this->never())->method('setUserValue');
		$config->expects($this->never())->method('deleteUserValue');

		$service = $this->buildService($etherpadClient, $config);
		$service->createProtectedOpenContext($uid, $displayName, $padId);
	}
}
