<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;

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
		?\OCP\ICacheFactory $cacheFactory = null,
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
			new FixedClock(),
			$this->linkSessions($cacheFactory ?? $this->noCache(), $etherpadClient),
		);
	}

	/**
	 * A signed-in user's protected open leaves the author's id for the
	 * sweep; a public link's notes its ids once it made a session (below).
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
		// A signed-in user's sessions are collected by author alone.
		$collector->expects($this->never())->method('noteGroup');

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
	 * A signed-in open notes its author first, so an open that fails after
	 * it still leaves the author for the sweep.
	 */
	public function testNotesASignedInAuthorBeforeTheListing(): void {
		$events = [];
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createSession')->willReturn($this->sid('new'));
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturn('a.author');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		$etherpadClient->method('listSessionsOfAuthor')->willReturnCallback(static function () use (&$events): array {
			$events[] = 'listed';
			return [];
		});
		$collector = $this->createMock(\OCA\EtherpadNextcloud\Service\ExpiredSessionCollector::class);
		$collector->method('noteAuthor')->willReturnCallback(static function () use (&$events): void {
			$events[] = 'noted';
		});

		$this->buildService($etherpadClient, $this->createMock(IConfig::class), incomingSessionCookie: $this->sid('carried'), collector: $collector)
			->createProtectedOpenContext('admin', 'Admin', 'g.ABCDEFGHIJKLMNOP$pad-1');

		$this->assertSame(['noted', 'listed'], $events);
	}

	/**
	 * A public link's open notes its group and its author
	 * (ExpiredSessionCollector says why both) - once it made a session: one
	 * handed out again within the hour adds nothing to collect.
	 */
	public function testAPublicLinkTellsTheCollectorItsGroupAndItsAuthorWhenItMadeASession(): void {
		[$etherpadClient, $config] = $this->publicLinkFixtures();
		$etherpadClient->method('getSessionInfo')->willReturn(['groupID' => 'g.ABCDEFGHIJKLMNOP', 'authorID' => 'a.public', 'validUntil' => FixedClock::NOW + 9000]);
		$collector = $this->createMock(\OCA\EtherpadNextcloud\Service\ExpiredSessionCollector::class);
		$collector->expects($this->once())->method('noteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$collector->expects($this->once())->method('noteAuthor')->with('a.public');
		$service = $this->buildService($etherpadClient, $config, collector: $collector, cacheFactory: $this->cacheFor());

		$service->createProtectedOpenContext('public-share:token', 'Public share', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800);
		// Handed out again: nothing more noted.
		$service->createProtectedOpenContext('public-share:token', 'Public share', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800);
	}

	/**
	 * A link's visitor with an author of their own is noted by group alone:
	 * a sweep for each visitor's author would be a job a visitor.
	 */
	public function testAVisitorOfALinkIsNotedByGroupAlone(): void {
		[$etherpadClient, $config] = $this->publicLinkFixtures();
		$collector = $this->createMock(\OCA\EtherpadNextcloud\Service\ExpiredSessionCollector::class);
		$collector->expects($this->once())->method('noteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$collector->expects($this->never())->method('noteAuthor');
		$service = $this->buildService($etherpadClient, $config, collector: $collector, cacheFactory: $this->cacheFor());

		$service->createProtectedOpenContext('public-share:token:' . str_repeat('0f', 16), '', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800);
	}

	/**
	 * A kept author is made anew only when Etherpad says it does not know
	 * it. An outage fails the open at the first call that times out: asking
	 * for the author again would only wait again - for a visitor of a link
	 * as for a signed-in user.
	 */
	public function testAnOutageFailsAnOpenWithAKeptAuthorAtOnce(): void {
		$timeout = new EtherpadClientException('Etherpad API request failed: createSession', 0, new \RuntimeException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received'));
		$opens = [
			'a visitor' => ['public-share:token:' . str_repeat('0f', 16), '', 'a.kept', 0],
			'a signed-in user' => ['admin', 'Admin', '', 1],
		];
		foreach ($opens as $case => [$uid, $name, $kept, $nameSyncs]) {
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->expects($this->once())->method('createSession')->willThrowException($timeout);
			$etherpadClient->expects($this->exactly($nameSyncs))->method('createAuthorIfNotExistsFor')->willReturn('a.kept');
			$config = $this->createMock(IConfig::class);
			$config->method('getUserValue')->willReturnMap([
				['admin', 'etherpad_nextcloud', 'etherpad_author_id', '', 'a.kept'],
				['admin', 'etherpad_nextcloud', 'etherpad_author_display_name', '', 'Admin'],
			]);
			try {
				$this->buildService($etherpadClient, $config)->createProtectedOpenContext($uid, $name, 'g.ABCDEFGHIJKLMNOP$pad-1', 10800, $kept);
				$this->fail($case . ': opened');
			} catch (EtherpadClientException $e) {
				$this->assertSame($timeout, $e, $case);
			}
		}
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
			// Read whole, as a revoke's is: no cap.
			$etherpadClient->method('listSessionsOfAuthor')->with('a.author', self::anything(), self::anything(), self::isNull())->willReturn($sessions);
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
			[$other => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => FixedClock::NOW + 3600]],
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
				$short => ['groupID' => 'g.BBBBBBBBBBBBBBBB', 'validUntil' => FixedClock::NOW + 600],
				$long => ['groupID' => 'g.BBBBBBBBBBBBBBBB', 'validUntil' => FixedClock::NOW + 3600],
				$c => ['groupID' => 'g.CCCCCCCCCCCCCCCC', 'validUntil' => FixedClock::NOW + 1200],
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
			[$this->sid('revoked') => ['groupID' => 'g.REVOKEDGROUP000', 'validUntil' => FixedClock::NOW + 3600]],
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
			[$held => ['groupID' => 'g.ABCDEFGHIJKLMNOP', 'validUntil' => FixedClock::NOW + 3600]],
			$held,
		)['value'];

		$this->assertSame($this->sid('new'), $value);
	}

	public function testDropsSessionsThatHaveExpired(): void {
		$dead = $this->sid('dead');

		$value = $this->openContextCookie(
			[$dead => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => FixedClock::NOW - 10]],
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
			[$known => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => FixedClock::NOW + 3600]],
			implode(',', array_merge($foreign, [$known])),
		)['value'];

		$this->assertSame($this->sid('new') . ',' . $known, $value);
	}

	/**
	 * Derived from the TTL rather than a literal: it has to outlast the new
	 * session whatever that is, or the expiry could come from it alone.
	 */
	public function testTheCookieOutlivesEveryIdItCarries(): void {
		$longer = FixedClock::NOW + PadSessionService::SESSION_TTL_SECONDS + 3600;
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
			$sessions[$id] = ['groupID' => 'g.GROUP' . str_pad((string)$i, 11, '0', STR_PAD_LEFT), 'validUntil' => FixedClock::NOW + 600 + $i];
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
			[$one => ['groupID' => 'g.OTHERGROUP00000', 'validUntil' => FixedClock::NOW + 3600]],
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
			new FixedClock(),
			$this->linkSessions($this->noCache(), $etherpadClient),
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

		// The file's own metadata contradict themselves: not Etherpad failing.
		$this->expectException(PadFileFormatException::class);
		$this->expectExceptionMessage('Protected pad ID is invalid');
		$service->extractGroupId('not-a-group-pad-id');
	}

	/** The name goes to Etherpad as it comes - a caller without one gives none - and the TTL has a floor. */
	public function testCreateProtectedOpenContextGivesTheNameAsItComesAndAMinimumTtl(): void {
		$uid = 'admin';
		$padId = 'g.ABCDEFGHIJKLMNOP$pad-1';
		$groupId = 'g.ABCDEFGHIJKLMNOP';
		$authorId = 'a.test-author';
		$sessionId = 's.test-session';
		$padUrl = 'https://pad.example.test/p/' . rawurlencode($padId);
		$before = FixedClock::NOW;

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createAuthorIfNotExistsFor')
			->with('nc:' . $uid, '')
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
		$result = $service->createProtectedOpenContext($uid, '', $padId, 10);
		$resultUrl = $result['url'];

		$this->assertSame($padUrl, $resultUrl);
		$this->assertSame('sessionID', $result['cookie']['name']);
		$this->assertSame($sessionId, $result['cookie']['value']);
		$this->assertSame('.example.test', $result['cookie']['domain']);
		$this->assertSame('Lax', $result['cookie']['same_site']);
		$this->assertTrue($result['cookie']['secure']);
		$this->assertSame($authorId, $result['authorId']);
	}

	/**
	 * An author kept elsewhere - a public link's visitor's, in their
	 * session - is opened as without asking Etherpad for it, and handed
	 * back; one Etherpad no longer has is asked for anew.
	 */
	public function testOpensAsAKnownAuthorWithoutAskingForIt(): void {
		[$etherpadClient, $config] = $this->publicLinkFixtures();
		$etherpadClient->expects($this->never())->method('createAuthorIfNotExistsFor');
		$service = $this->buildService($etherpadClient, $config);

		$result = $service->createProtectedOpenContext('public-share:token:' . str_repeat('0f', 16), '', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800, 'a.kept');

		$this->assertSame('a.kept', $result['authorId']);
	}

	public function testAsksForTheAuthorAnewWhenTheKnownOneFails(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createSession')->willReturnCallback(static function (string $groupId, string $authorId): string {
			if ($authorId === 'a.gone') {
				throw new EtherpadRefusedException('Etherpad API error (createSession): authorID does not exist');
			}
			return 's.made0000000001';
		});
		$etherpadClient->expects($this->once())->method('createAuthorIfNotExistsFor')->with('nc:public-share:token:' . str_repeat('0f', 16), '')->willReturn('a.anew');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/pad');
		[, $config] = $this->publicLinkFixtures();
		$service = $this->buildService($etherpadClient, $config);

		$result = $service->createProtectedOpenContext('public-share:token:' . str_repeat('0f', 16), '', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800, 'a.gone');

		$this->assertSame('a.anew', $result['authorId']);
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
			'expires' => FixedClock::NOW + 3600,
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
					throw new EtherpadRefusedException('Etherpad API error (createSession): authorID does not exist');
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

	/**
	 * The session made for a link's opener in the last hour is handed out
	 * again - here the link's own author, which visitors past the hour's
	 * count share: opened twice, the link makes one session, and the
	 * second cookie carries it with the expiry Etherpad gave for it.
	 */
	public function testAPublicLinkHandsOutTheSessionItMadeInTheLastHour(): void {
		[$etherpadClient, $config, $made, $calls] = $this->publicLinkFixtures();
		$etherpadClient->method('getSessionInfo')->willReturnCallback(
			static fn (string $sessionId): ?array => $sessionId === 's.public0000000000001'
				? ['groupID' => 'g.ABCDEFGHIJKLMNOP', 'authorID' => 'a.public', 'validUntil' => FixedClock::NOW + 9000]
				: null
		);
		$service = $this->buildService($etherpadClient, $config, cacheFactory: $this->cacheFor());

		$first = $service->createProtectedOpenContext('public-share:token', 'Public share', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800);
		$second = $service->createProtectedOpenContext('public-share:token', 'Public share', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800);

		$this->assertSame(1, $made->count());
		$this->assertSame(['g.ABCDEFGHIJKLMNOP', 'a.public', FixedClock::NOW + 10800], $calls[0]);
		$this->assertSame('s.public0000000000001', $first['cookie']['value']);
		$this->assertSame('s.public0000000000001', $second['cookie']['value']);
		$this->assertSame(FixedClock::NOW + 9000, $second['cookie']['expires']);
	}

	/**
	 * Etherpad decides, not the cache: a kept session it no longer has -
	 * taken away when the file went to the trash, say - is not handed out,
	 * and the open makes and keeps a new one, which the next open gets.
	 */
	public function testAPublicLinkMakesANewSessionWhenEtherpadNoLongerHasTheKeptOne(): void {
		[$etherpadClient, $config, $made] = $this->publicLinkFixtures();
		$etherpadClient->method('getSessionInfo')->willReturnCallback(
			static fn (string $sessionId): ?array => $sessionId === 's.public0000000000002'
				? ['groupID' => 'g.ABCDEFGHIJKLMNOP', 'authorID' => 'a.public', 'validUntil' => FixedClock::NOW + 10800]
				: null
		);
		$service = $this->buildService($etherpadClient, $config, cacheFactory: $this->cacheFor());

		$service->createProtectedOpenContext('public-share:token', 'Public share', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800);
		$second = $service->createProtectedOpenContext('public-share:token', 'Public share', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800);
		$third = $service->createProtectedOpenContext('public-share:token', 'Public share', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800);

		$this->assertSame(2, $made->count());
		$this->assertSame('s.public0000000000002', $second['cookie']['value']);
		$this->assertSame('s.public0000000000002', $third['cookie']['value']);
	}

	/**
	 * A visitor of a link opens with no name, which Etherpad lets them set:
	 * not the uid in its place, which carries the share's token.
	 */
	public function testAPublicLinksVisitorIsGivenNoName(): void {
		[$etherpadClient, $config] = $this->publicLinkFixtures();
		$names = new \ArrayObject();
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturnCallback(
			static function (string $mapper, string $name) use ($names): string {
				$names->append([$mapper, $name]);
				return 'a.visitor';
			}
		);
		$etherpadClient->method('createSession')->willReturn('s.visitor00000000000001');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/pad');

		$this->buildService($etherpadClient, $config)->createProtectedOpenContext('public-share:token:0123456789abcdef0123456789abcdef', '', 'g.ABCDEFGHIJKLMNOP$pad-1', 10800);

		$this->assertSame([['nc:public-share:token:0123456789abcdef0123456789abcdef', '']], $names->getArrayCopy());
	}

	/** A signed-in open still makes a session of its own every time. */
	public function testASignedInOpenDoesNotTakeAKeptSession(): void {
		[$etherpadClient, $config, $made] = $this->publicLinkFixtures();
		$etherpadClient->expects($this->never())->method('getSessionInfo');
		$service = $this->buildService($etherpadClient, $config, cacheFactory: $this->cacheFor());

		$service->createProtectedOpenContext('alice', 'Alice', 'g.ABCDEFGHIJKLMNOP$pad-1');
		$service->createProtectedOpenContext('alice', 'Alice', 'g.ABCDEFGHIJKLMNOP$pad-1');

		$this->assertSame(2, $made->count());
	}

	/**
	 * @return array{0: EtherpadClient&\PHPUnit\Framework\MockObject\MockObject, 1: IConfig, 2: \ArrayObject<int, string>, 3: \ArrayObject<int, array{string, string, int}>}
	 */
	private function publicLinkFixtures(): array {
		$made = new \ArrayObject();
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createAuthorIfNotExistsFor')->willReturn('a.public');
		$etherpadClient->method('configuredApiHost')->willReturn('https://pad.example.test');
		$calls = new \ArrayObject();
		$etherpadClient->method('createSession')->willReturnCallback(
			static function (string $groupId, string $authorId, int $validUntil) use ($made, $calls): string {
				$calls->append([$groupId, $authorId, $validUntil]);
				$made->append(sprintf('s.public%013d', $made->count() + 1));
				return $made[$made->count() - 1];
			}
		);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/pad');
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['etherpad_nextcloud', 'etherpad_cookie_domain', '', ''],
			['etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no', 'no'],
			['etherpad_nextcloud', 'etherpad_host', '', 'https://pad.example.test'],
			['etherpad_nextcloud', PadSessionService::SAME_SITE_KEY, 'lax', 'lax'],
		]);
		$config->method('getUserValue')->willReturn('');
		return [$etherpadClient, $config, $made, $calls];
	}

	/** A memory cache, as a distributed one would answer. */
	private function cacheFor(): \OCP\ICacheFactory {
		$held = new \ArrayObject();
		$cache = $this->createMock(\OCP\ICache::class);
		$cache->method('get')->willReturnCallback(static fn (string $key): mixed => $held[$key] ?? null);
		$cache->method('set')->willReturnCallback(
			static function (string $key, mixed $value) use ($held): bool {
				$held[$key] = $value;
				return true;
			}
		);
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		return $factory;
	}

	private function linkSessions(\OCP\ICacheFactory $cacheFactory, EtherpadClient $etherpadClient): \OCA\EtherpadNextcloud\Service\PublicLinkSessions {
		$crypto = $this->createMock(\OCP\Security\ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash('sha256', $message, true));
		return new \OCA\EtherpadNextcloud\Service\PublicLinkSessions(new \OCA\EtherpadNextcloud\Service\PublicLinkCache($cacheFactory, $crypto), $etherpadClient, new FixedClock(), $this->createMock(LoggerInterface::class));
	}

	/** No memory cache: Nextcloud hands out one that keeps nothing. */
	private function noCache(): \OCP\ICacheFactory {
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$factory->method('createDistributed')->willReturn($this->createMock(\OCP\ICache::class));
		return $factory;
	}
}
