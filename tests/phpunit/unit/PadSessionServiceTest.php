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
use Psr\Log\LoggerInterface;

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
		bool $httpOnlySupported = false,
		?\OCA\EtherpadNextcloud\Service\ExpiredSessionCollector $collector = null,
	): PadSessionService {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn($nextcloudUrl);
		$request = $this->createMock(IRequest::class);
		$request->method('getCookie')->with('sessionID')->willReturn($incomingSessionCookie);
		$releasePolicy = $this->createMock(\OCA\EtherpadNextcloud\Service\EtherpadReleasePolicy::class);
		$releasePolicy->method('supportsHttpOnlySessionCookie')->willReturn($httpOnlySupported);
		return new PadSessionService(
			$etherpadClient,
			$config,
			$urlGenerator,
			new CookieDomainPolicy(),
			$releasePolicy,
			$request,
			$collector ?? $this->createMock(\OCA\EtherpadNextcloud\Service\ExpiredSessionCollector::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * Every protected open leaves the author's id for the sweep, before
	 * anything is asked of the pad server.
	 *
	 * Not "when a backlog is noticed": noticing one needs the listing, and
	 * the listing only happens when the browser carries session ids — so a
	 * first open made none, and neither does a public link. Both were
	 * invisible to a sweep that had to be told what to look at.
	 */
	public function testTellsTheCollectorWhoOpenedThePad(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createSession')->willReturn($this->sid('new'));
		$etherpadClient->expects($this->never())->method('listSessionsOfAuthor');
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturn('a.author');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');

		$collector = $this->createMock(\OCA\EtherpadNextcloud\Service\ExpiredSessionCollector::class);
		// The author id alone. For a public link the uid is the share token,
		// and this argument is persisted in the jobs table.
		$collector->expects($this->once())->method('noteAuthor')->with('a.author');

		// No incoming cookie: the case the old trigger could never see.
		$service = $this->buildService(
			$etherpadClient,
			$this->createMock(IConfig::class),
			'https://cloud.example.test',
			null,
			false,
			$collector,
		);
		$service->createProtectedOpenContext('admin', 'Admin', 'g.ABCDEFGHIJKLMNOP$pad-1');
	}

	/**
	 * A failed open must not take the ability to revoke with it.
	 *
	 * The author id is the only route from a uid to that user's live
	 * sessions. Dropping it when an open fails leaves a cache that cannot
	 * be told apart from a user who never opened a protected pad – so a
	 * logout after a brief pad-server outage would revoke nothing while
	 * sessions from before it were still valid.
	 */
	public function testKeepsTheAuthorIdWhenAnOpenFails(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createSession')
			->willThrowException(new EtherpadClientException('unavailable'));
		$etherpadClient->method('createAuthorIfNotExistsFor')
			->willThrowException(new EtherpadClientException('unavailable'));

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnMap([
			['admin', 'etherpad_nextcloud', 'etherpad_author_id', '', 'a.author'],
			['admin', 'etherpad_nextcloud', 'etherpad_author_display_name', '', 'Admin'],
		]);
		$config->expects($this->never())->method('deleteUserValue');

		$service = $this->buildService($etherpadClient, $config);
		$this->expectException(EtherpadClientException::class);
		$service->createProtectedOpenContext('admin', 'Admin', 'g.ABCDEFGHIJKLMNOP$pad-1');
	}

	/**
	 * Etherpad's real shape: `s.` plus 16 characters. The `x` separates the
	 * label from the padding, so `g1` and `g10` do not collide.
	 */
	private function sid(string $label): string {
		return 's.' . substr(str_pad($label . 'x', 16, '0'), 0, 16);
	}

	/**
	 * Each protected pad is its own Etherpad group, and a session grants
	 * access to one group. The cookie is the only place that state lives,
	 * so an open must not write away what the browser already carried.
	 *
	 * @param array<string,array{groupID:string,validUntil:int}> $sessions
	 * @return array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string}
	 */
	private function openContextCookie(
		array $sessions,
		?string $incoming = null,
		string $groupId = 'g.ABCDEFGHIJKLMNOP',
		bool $listingFails = false,
		bool $httpOnlySupported = false,
		string $sameSiteSetting = 'lax',
	): array {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createSession')->willReturn($this->sid('new'));
		if ($listingFails) {
			$etherpadClient->method('listSessionsOfAuthor')
				->willThrowException(new EtherpadClientException('unavailable'));
		} else {
			$etherpadClient->method('listSessionsOfAuthor')->willReturn($sessions);
		}
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturn('a.author');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === PadSessionService::SAME_SITE_KEY
				? $sameSiteSetting
				: $default
		);
		$service = $this->buildService(
			$etherpadClient,
			$config,
			'https://cloud.example.test',
			$incoming,
			$httpOnlySupported,
		);

		return $service->createProtectedOpenContext('admin', 'Admin', $groupId . '$pad-1')['cookie'];
	}

	/**
	 * The pad app up to Etherpad 2.7.3 reads `sessionID` itself, in the
	 * browser. HttpOnly there is not a hardening, it is a lockout.
	 */
	public function testLeavesTheCookieReadableForAnEtherpadThatReadsItInTheBrowser(): void {
		$cookie = $this->openContextCookie([], null, httpOnlySupported: false);
		$this->assertFalse($cookie['http_only']);
	}

	/**
	 * From 3.0.0 the session id comes out of the socket.io handshake on the
	 * server, so nothing on the page needs to see it — and a script that
	 * gets onto the page cannot take it.
	 */
	public function testKeepsTheCookieFromScriptsWhereEtherpadReadsItServerSide(): void {
		$cookie = $this->openContextCookie([], null, httpOnlySupported: true);
		$this->assertTrue($cookie['http_only']);
	}

	public function testWritesOnlyTheNewSessionWhenTheBrowserSentNone(): void {
		$this->assertSame($this->sid('new'), $this->openContextCookie([], null)['value']);
	}

	public function testKeepsAnotherPadsSessionTheBrowserWasCarrying(): void {
		$other = $this->sid('other');

		$value = $this->openContextCookie(
			[$other => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => time() + 3600]],
			$other,
		)['value'];

		$this->assertSame($this->sid('new') . ',' . $other, $value);
	}

	/**
	 * The point of asking Etherpad: without the group behind each id, ten
	 * opens of one pad filled the cookie with ten of its ids and pushed the
	 * other pad out.
	 */
	public function testCollapsesSeveralSessionsOfOneGroupIntoTheLongestLiving(): void {
		$short = $this->sid('bshort');
		$long = $this->sid('blong');
		$c = $this->sid('c');

		$value = $this->openContextCookie(
			[
				$short => ['groupID' => 'g.BBBBBBBBBBBBBBBB', 'validUntil' => time() + 600],
				$long => ['groupID' => 'g.BBBBBBBBBBBBBBBB', 'validUntil' => time() + 3600],
				$c => ['groupID' => 'g.CCCCCCCCCCCCCCCC', 'validUntil' => time() + 1200],
			],
			implode(',', [$short, $long, $c]),
		)['value'];

		$this->assertSame(implode(',', [$this->sid('new'), $long, $c]), $value);
	}

	/**
	 * Nothing is added that the browser was not already carrying. An open
	 * must not re-issue access to a pad the user has since lost — it only
	 * refrains from taking away what they held, which dies on its own.
	 */
	public function testDoesNotReissueSessionsTheBrowserDidNotSend(): void {
		$value = $this->openContextCookie(
			[$this->sid('revoked') => ['groupID' => 'g.REVOKEDGROUP000', 'validUntil' => time() + 3600]],
			null,
		)['value'];

		$this->assertSame($this->sid('new'), $value);
	}

	/**
	 * An open always mints. Etherpad re-checks validUntil on every socket
	 * message and keeps the session id it was handed at CLIENT_READY, so a
	 * session that expires mid-edit rejects the next keystroke and no later
	 * cookie reaches that socket — reusing a shorter one would hand out
	 * less editing time than the caller asked for.
	 */
	public function testAlwaysIssuesAFreshSessionForThePadBeingOpened(): void {
		$held = $this->sid('held');

		$value = $this->openContextCookie(
			[$held => ['groupID' => 'g.ABCDEFGHIJKLMNOP', 'validUntil' => time() + 3600]],
			$held,
		)['value'];

		$this->assertSame($this->sid('new'), $value);
	}

	public function testDropsSessionsThatHaveExpired(): void {
		$dead = $this->sid('dead');

		$value = $this->openContextCookie(
			[$dead => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => time() - 10]],
			$dead,
		)['value'];

		$this->assertSame($this->sid('new'), $value);
	}

	/**
	 * The session of whoever used this browser before belongs to their
	 * Etherpad author, so it is not in this one's listing. Carrying it would
	 * hand the next person to log in a pad that is not theirs — and a public
	 * share's session, which is also its own author, is indistinguishable
	 * from it. Both are dropped; the share case was already broken before
	 * any of this, the other one would have been new.
	 */
	public function testDropsIdsTheListingDoesNotKnow(): void {
		$known = $this->sid('known');
		$foreign = array_map(fn (int $i): string => $this->sid('foreign' . $i), range(1, 8));

		$value = $this->openContextCookie(
			[$known => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => time() + 3600]],
			implode(',', array_merge($foreign, [$known])),
		)['value'];

		$this->assertSame($this->sid('new') . ',' . $known, $value);
	}

	/**
	 * Strictly longer than the new session's hour, so this cannot pass with
	 * the expiry taken from the new session alone — and cannot fail because
	 * a second ticked between two time() calls.
	 */
	public function testTheCookieOutlivesEveryIdItCarries(): void {
		$longer = time() + 7200;
		$other = $this->sid('other');

		$cookie = $this->openContextCookie(
			[$other => ['groupID' => 'g.BBBBBBBBBBBBBBBB', 'validUntil' => $longer]],
			$other,
		);

		$this->assertSame($this->sid('new') . ',' . $other, $cookie['value']);
		$this->assertSame($longer, $cookie['expires']);
	}

	public function testKeepsTheCookieBoundedAndDropsWhatExpiresSoonest(): void {
		$sessions = [];
		$ids = [];
		// A full cookie: the most this can ever have emitted.
		foreach (range(1, 25) as $i) {
			$id = $this->sid('g' . $i);
			$ids[] = $id;
			$sessions[$id] = ['groupID' => 'g.GROUP' . str_pad((string)$i, 11, '0', STR_PAD_LEFT), 'validUntil' => time() + 600 + $i];
		}

		$value = $this->openContextCookie($sessions, implode(',', $ids))['value'];
		$kept = explode(',', $value);

		$this->assertCount(25, $kept);
		$this->assertSame($this->sid('new'), $kept[0]);
		// Longest-lived first after the pad being opened, soonest dropped.
		$this->assertSame($this->sid('g25'), $kept[1]);
		$this->assertNotContains($this->sid('g1'), $kept);
		// 25 ids of 18 bytes with percent-encoded separators.
		$this->assertLessThan(600, strlen($value));
	}

	/**
	 * Any host under the shared parent domain can write this cookie, so the
	 * parse must not grow with what it finds there. Nothing beyond what
	 * could ever be emitted again is even looked at.
	 */
	public function testIgnoresMoreCookieIdsThanItCouldEverEmit(): void {
		$ids = array_map(fn (int $i): string => $this->sid('junk' . $i), range(1, 100));

		$value = $this->openContextCookie([], implode(',', $ids))['value'];

		$this->assertSame($this->sid('new'), $value);
	}

	public function testIgnoresCookieValuesThatAreNotSessionIds(): void {
		$value = $this->openContextCookie([], 'nonsense; HttpOnly,../etc,s.,s.' . str_repeat('a', 200))['value'];

		$this->assertSame($this->sid('new'), $value);
	}

	/**
	 * RFC 6265 lets a server quote a value that contains commas, and
	 * Etherpad strips those quotes itself. The parsing has to as well, or a
	 * quoted cookie would look like one unusable id.
	 */
	public function testAcceptsTheQuotedCookieForm(): void {
		$one = $this->sid('one');

		$value = $this->openContextCookie(
			[$one => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => time() + 3600]],
			'"' . $one . ' "',
		)['value'];

		$this->assertSame($this->sid('new') . ',' . $one, $value);
	}

	/**
	 * Without the listing nothing can be attributed, so nothing is carried:
	 * the open falls back to exactly what it did before this branch, one
	 * fresh id, rather than to a rule it cannot enforce.
	 */
	public function testCarriesNothingWhenTheListingFails(): void {
		$carried = array_map(fn (int $i): string => $this->sid('old' . $i), range(1, 8));

		$value = $this->openContextCookie([], implode(',', $carried), 'g.ABCDEFGHIJKLMNOP', true)['value'];

		$this->assertSame($this->sid('new'), $value);
	}

	/**
	 * The listing is the call whose cost grows with every distinct pad the
	 * user has ever opened. With nothing in the cookie there is nothing to
	 * annotate, so the first open of a browsing session does not pay for it.
	 */
	public function testDoesNotAskForTheListingWhenTheBrowserSentNoSessions(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('listSessionsOfAuthor');
		$etherpadClient->method('createSession')->willReturn($this->sid('new'));
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturn('a.author');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');

		$service = $this->buildService($etherpadClient, $this->createMock(IConfig::class));

		$cookie = $service->createProtectedOpenContext('admin', 'Admin', 'g.ABCDEFGHIJKLMNOP$pad-1')['cookie'];

		$this->assertSame($this->sid('new'), $cookie['value']);
	}

	public function testLogsWhenTheListingIsUnavailable(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createSession')->willReturn($this->sid('new'));
		$etherpadClient->method('listSessionsOfAuthor')
			->willThrowException(new EtherpadClientException('unavailable'));
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturn('a.author');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.test');
		// The listing is only asked for when the browser sent ids to annotate.
		$request = $this->createMock(IRequest::class);
		$request->method('getCookie')->with('sessionID')->willReturn($this->sid('carried'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$service = new PadSessionService(
			$etherpadClient,
			$this->createMock(IConfig::class),
			$urlGenerator,
			new CookieDomainPolicy(),
			$this->createMock(\OCA\EtherpadNextcloud\Service\EtherpadReleasePolicy::class),
			$request,
			$this->createMock(\OCA\EtherpadNextcloud\Service\ExpiredSessionCollector::class),
			$logger,
		);

		$service->createProtectedOpenContext('admin', 'Admin', 'g.ABCDEFGHIJKLMNOP$pad-1');
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
			['etherpad_nextcloud', PadSessionService::SAME_SITE_KEY, 'lax', 'lax'],
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
				['etherpad_nextcloud', PadSessionService::SAME_SITE_KEY, 'lax', 'lax'],
			]);

		$service = $this->buildService($etherpadClient, $config);
		$result = $service->createProtectedOpenContext($uid, '   ', $padId, 10);
		$resultUrl = $result['url'];

		$this->assertSame($padUrl, $resultUrl);
		$this->assertSame('sessionID', $result['cookie']['name']);
		$this->assertSame($sessionId, $result['cookie']['value']);
		$this->assertSame('.example.test', $result['cookie']['domain']);
		$this->assertSame('Lax', $result['cookie']['same_site']);
		$this->assertTrue($result['cookie']['secure']);
	}

	/**
	 * `Lax` covers the ordinary chain by itself: Nextcloud and Etherpad have
	 * to share a registrable domain for the cookie to be settable at all, so
	 * the pad iframe is a same-site subresource — while a foreign page
	 * framing a pad URL gets nothing.
	 *
	 * Not `Strict`, which is the other direction this could drift: that
	 * withholds the cookie from a top-level navigation too, so a pad link
	 * in an email would open unauthenticated.
	 */
	public function testTheSessionCookieDoesNotTravelToOtherSites(): void {
		$this->assertSame('Lax', $this->openContextCookie([], null)['same_site']);
	}

	/**
	 * Asked for, never inferred. The one deployment that needs it is a
	 * foreign site framing the embed routes where Nextcloud authenticates
	 * without a cookie — proxy `REMOTE_USER`, Kerberos, SAML in environment
	 * mode. Nothing in a cookie policy can see that.
	 */
	public function testTheCookieIsNotStrict(): void {
		// A later hardening pass reads the comment above, sees "do not give
		// this to other sites", and reaches for Strict. That breaks opening
		// a pad from a link, and nothing else in the suite would notice.
		$this->assertNotSame('Strict', $this->openContextCookie([], null)['same_site']);
	}

	public function testAnAdminCanWidenTheCookieForACrossSiteEmbed(): void {
		$this->assertSame('None', $this->openContextCookie([], null, sameSiteSetting: 'none')['same_site']);
	}

	/** Anything else is Lax, including a value nobody meant. */
	public function testAnythingButNoneMeansLax(): void {
		$this->assertSame('Lax', $this->openContextCookie([], null, sameSiteSetting: 'true')['same_site']);
		$this->assertSame('Lax', $this->openContextCookie([], null, sameSiteSetting: '')['same_site']);
	}

	/**
	 * But it is not swallowed. `strict` is the one that stings: somebody
	 * meant to harden and gets the opposite, and without this the only
	 * evidence is the cookie itself.
	 *
	 * @param string $stored
	 * @param string $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('sameSiteValues')]
	public function testAnUnrecognisedSameSiteValueIsReported(string $stored, string $expected): void {
		$service = $this->buildService(
			$this->createMock(EtherpadClient::class),
			$this->configReturning(PadSessionService::SAME_SITE_KEY, $stored),
		);

		$this->assertSame($expected, $service->unrecognisedSameSite());
	}

	/** @return array<string,array{string,string}> */
	public static function sameSiteValues(): array {
		return [
			'none' => ['none', ''],
			'lax' => ['lax', ''],
			'unset' => ['', ''],
			'strict' => ['strict', 'strict'],
			'off' => ['off', 'off'],
			'cross-site' => ['cross-site', 'cross-site'],
		];
	}

	private function configReturning(string $key, string $value): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $wanted, string $default = ''): string => $wanted === $key ? $value : $default
		);
		return $config;
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
				['etherpad_nextcloud', PadSessionService::SAME_SITE_KEY, 'lax', 'lax'],
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
				['etherpad_nextcloud', PadSessionService::SAME_SITE_KEY, 'lax', 'lax'],
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
			'same_site' => 'Lax',
		]);

		$this->assertStringContainsString('sessionID=s.abc123', $header);
		$this->assertStringContainsString('Expires=', $header);
		$this->assertStringContainsString('Max-Age=', $header);
		$this->assertStringContainsString('Path=/', $header);
		$this->assertStringContainsString('Domain=.example.test', $header);
		$this->assertStringContainsString('Secure', $header);
		$this->assertStringContainsString('SameSite=Lax', $header);
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
				['etherpad_nextcloud', PadSessionService::SAME_SITE_KEY, 'lax', 'lax'],
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
				['etherpad_nextcloud', PadSessionService::SAME_SITE_KEY, 'lax', 'lax'],
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
				['etherpad_nextcloud', PadSessionService::SAME_SITE_KEY, 'lax', 'lax'],
			]);
		$config->method('getUserValue')
			->willReturnMap([
				[$uid, 'etherpad_nextcloud', 'etherpad_author_id', '', $cachedAuthorId],
				[$uid, 'etherpad_nextcloud', 'etherpad_author_display_name', '', $displayName],
			]);
		// Nothing is cleared: the id is the only way to find this user's
		// live sessions again, and the name is rewritten by the bootstrap
		// below in any case.
		$config->expects($this->never())->method('deleteUserValue');
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
				['etherpad_nextcloud', PadSessionService::SAME_SITE_KEY, 'lax', 'lax'],
			]);
		$config->expects($this->never())->method('setUserValue');
		$config->expects($this->never())->method('deleteUserValue');

		$service = $this->buildService($etherpadClient, $config);
		$service->createProtectedOpenContext($uid, $displayName, $padId);
	}
}
