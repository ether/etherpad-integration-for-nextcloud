<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Util\ApiKey;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadKeyRejectedException;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCP\Http\Client\IClient;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Http\BoundedSinkStream;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class EtherpadClientTest extends TestCase {
	public function testBuildPadUrlUsesConfiguredHost(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'etherpad_host') {
					return 'https://pad.example.test';
				}
				return $default;
			}
		);

		$client = $this->client($config);
		$this->assertSame(
			'https://pad.example.test/p/g.group%24pad-name',
			$client->buildPadUrl('g.group$pad-name')
		);
	}

	/**
	 * The API host where one is set, the public one otherwise, as calls go;
	 * '' while neither is, where getApiHost() throws, so the admin page can
	 * compare a typed address with it before the first save.
	 */
	public function testTheConfiguredApiHostFallsBackToThePublicOneAndThenToNothing(): void {
		$apiAndPublic = $this->createMock(IConfig::class);
		$apiAndPublic->method('getAppValue')->willReturnCallback(
			static fn (string $appName, string $key, string $default = ''): string => match ($key) {
				'etherpad_api_host' => 'http://etherpad:9001/',
				'etherpad_host' => 'https://pad.example.test',
				default => $default,
			}
		);

		$this->assertSame('http://etherpad:9001', $this->client($apiAndPublic)->configuredApiHost());
		$this->assertSame('https://pad.example.test', $this->client($this->configWithHost('https://pad.example.test/'))->configuredApiHost());
		$nothing = $this->client($this->configWithHost(''));
		$this->assertSame('', $nothing->configuredApiHost());
		$this->expectException(EtherpadClientException::class);
		$nothing->getApiHost();
	}

	public function testGetConfiguredOriginNormalizesScheme(): void {
		$client = $this->client($this->configWithHost('HTTPS://Pad.Example.Test/'));
		$this->assertSame('https://pad.example.test', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginReturnsEmptyWhenUnconfigured(): void {
		$client = $this->client($this->configWithHost(''));
		$this->assertSame('', $client->getConfiguredOrigin());
	}

	public function testApiCallReturnsDataOnSuccess(): void {
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"groupID":"g.abc123"}}')
		);
		$this->assertSame('g.abc123', $client->createGroup());
	}

	public function testApiCallSendsApiKeyFromSettingsRepository(): void {
		// Regression guard for #105: the apikey must come from
		// AdminSettingsRepository::getApiKey() (the decrypting IAppConfig path),
		// not from IConfig. Capture the outgoing request and assert it.
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"groupID":"g.abc123"}}'),
			$captured,
		);

		$client->createGroup();

		$this->assertNotNull($captured);
		// createGroup is a POST: apikey travels in the form-urlencoded body.
		$this->assertStringContainsString('apikey=stored-key', self::bodyOf($captured));
	}

	/**
	 * The session listing is a POST like every other authenticated call. As
	 * a GET the apikey would travel in the URL and from there into proxy and
	 * access logs.
	 */
	public function testListSessionsOfAuthorSendsTheKeyInTheBodyNotTheUrl(): void {
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"s.one":{"groupID":"g.aaa","authorID":"a.x","validUntil":99}}}'),
			$captured,
		);

		$sessions = $client->listSessionsOfAuthor('a.x');

		$this->assertSame('POST', $captured['method']);
		$this->assertStringNotContainsString('apikey', $captured['url']);
		$this->assertArrayNotHasKey('query', $captured['options']);
		$this->assertStringContainsString('apikey=stored-key', self::bodyOf($captured));
		$this->assertStringContainsString('authorID=a.x', self::bodyOf($captured));
		$this->assertSame(['s.one' => ['groupID' => 'g.aaa', 'validUntil' => 99]], $sessions);
	}

	public function testListSessionsOfAuthorSkipsEntriesWithoutAGroupOrExpiry(): void {
		$client = $this->clientWithResponse($this->response(200, json_encode([
			'code' => 0,
			'data' => [
				's.ok' => ['groupID' => 'g.aaa', 'validUntil' => 42],
				's.nogroup' => ['groupID' => '', 'validUntil' => 42],
				's.noexpiry' => ['groupID' => 'g.bbb', 'validUntil' => 0],
				's.notanarray' => 'nonsense',
			],
		])));

		$this->assertSame(['s.ok' => ['groupID' => 'g.aaa', 'validUntil' => 42]], $client->listSessionsOfAuthor('a.x'));
	}

	/** A group's sessions, asked for by its id and read as an author's are. */
	public function testListSessionsOfGroupReadsTheGroupsSessions(): void {
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"s.one":{"groupID":"g.aaa","authorID":"a.x","validUntil":99},"s.bad":{"groupID":"","validUntil":1}}}'),
			$captured,
		);

		$sessions = $client->listSessionsOfGroup('g.aaa', null, $unreadable);

		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('groupID=g.aaa', self::bodyOf($captured));
		$this->assertStringEndsWith('/listSessionsOfGroup', parse_url((string)$captured['url'], PHP_URL_PATH));
		$this->assertSame(['s.one' => ['groupID' => 'g.aaa', 'validUntil' => 99]], $sessions);
		$this->assertSame(1, $unreadable);
	}

	/** One session, asked for by its id: the group, the author and when it runs out. */
	public function testGetSessionInfoReadsTheSession(): void {
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"groupID":"g.aaa","authorID":"a.x","validUntil":1767236400}}'),
			$captured,
		);

		$this->assertSame(['groupID' => 'g.aaa', 'authorID' => 'a.x', 'validUntil' => 1767236400], $client->getSessionInfo('s.one'));
		// A number Etherpad writes another way is read as the listings read it.
		$this->assertSame(1767236400, $this->clientWithResponse($this->response(200, '{"code":0,"data":{"groupID":"g.aaa","authorID":"a.x","validUntil":1767236400.0}}'))->getSessionInfo('s.one')['validUntil'] ?? null);
		$this->assertStringContainsString('sessionID=s.one', self::bodyOf($captured));
		$this->assertStringEndsWith('/getSessionInfo', parse_url((string)$captured['url'], PHP_URL_PATH));
	}

	/** A session Etherpad does not have is none, not a failure: deleted, or collected after it ran out. */
	public function testGetSessionInfoReadsASessionGoneAsNone(): void {
		$this->assertNull($this->clientWithResponse($this->response(200, '{"code":1,"message":"sessionID does not exist","data":null}'))->getSessionInfo('s.gone'));
	}

	/**
	 * Anything else Etherpad says is a failure, a wrong API key among them,
	 * and so is an answer that does not describe a session: the ids have to
	 * be text and the expiry a positive number, or digits that spell one,
	 * before a caller compares the ids with its own and reuses the session
	 * until it expires.
	 *
	 * @param class-string<EtherpadClientException> $exception
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('sessionAnswersRefused')]
	public function testGetSessionInfoRefuses(string $body, string $exception, string $message): void {
		$this->expectException($exception);
		$this->expectExceptionMessage($message);

		$this->clientWithResponse($this->response(200, $body))->getSessionInfo('s.one');
	}

	/** @return array<string, array{string, class-string<EtherpadClientException>, string}> */
	public static function sessionAnswersRefused(): array {
		$undescribed = 'Etherpad did not describe the session.';
		return [
			'another refusal' => ['{"code":2,"message":"internal error","data":null}', EtherpadRefusedException::class, 'Etherpad API error (getSessionInfo): internal error'],
			'a wrong API key' => ['{"code":4,"message":"no or wrong API Key","data":null}', EtherpadKeyRejectedException::class, 'Etherpad API error (getSessionInfo): no or wrong API Key'],
			'an empty author' => ['{"code":0,"data":{"groupID":"g.aaa","authorID":"","validUntil":1}}', EtherpadClientException::class, $undescribed],
			'no expiry' => ['{"code":0,"data":{"groupID":"g.aaa","authorID":"a.x"}}', EtherpadClientException::class, $undescribed],
			'a group as a number' => ['{"code":0,"data":{"groupID":5,"authorID":"a.x","validUntil":99}}', EtherpadClientException::class, $undescribed],
			'an author as a number' => ['{"code":0,"data":{"groupID":"g.x","authorID":5,"validUntil":99}}', EtherpadClientException::class, $undescribed],
			'an expiry that is no number' => ['{"code":0,"data":{"groupID":"g.x","authorID":"a.x","validUntil":true}}', EtherpadClientException::class, $undescribed],
		];
	}

	public function testListPadsReadsAValidPadList(): void {
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"padIDs":["g.aaa$one","g.aaa$two"]}}')
		);
		$this->assertSame(['g.aaa$one', 'g.aaa$two'], $client->listPads('g.aaa'));
	}

	/** Measured against Etherpad: an empty group answers with an empty list. */
	public function testListPadsReadsAnEmptyGroup(): void {
		$client = $this->clientWithResponse($this->response(200, '{"code":0,"data":{"padIDs":[]}}'));
		$this->assertSame([], $client->listPads('g.aaa'));
	}

	/**
	 * Not `[]`, because of what the caller does with `[]`: it reads it as
	 * "this group holds nothing, so removing it takes nothing with it". An
	 * answer that does not contain a list is not an empty group, and
	 * shrugging it off would be a licence to delete. Saying so drops the
	 * caller back to deleting the pad alone.
	 *
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('provideUnreadablePadLists')]
	public function testListPadsRefusesAnAnswerItCannotRead(string $payload): void {
		$client = $this->clientWithResponse($this->response(200, $payload));
		$this->expectException(EtherpadClientException::class);
		$client->listPads('g.aaa');
	}

	/** @return array<string,array{string}> */
	public static function provideUnreadablePadLists(): array {
		return [
			'no padIDs field' => ['{"code":0,"data":{}}'],
			'null data' => ['{"code":0,"data":null}'],
			'padIDs is a string' => ['{"code":0,"data":{"padIDs":"g.aaa$one"}}'],
			'padIDs is an object' => ['{"code":0,"data":{"padIDs":{"a":"g.aaa$one"}}}'],
			'a member is not a string' => ['{"code":0,"data":{"padIDs":["g.aaa$one",42]}}'],
			'a member is null' => ['{"code":0,"data":{"padIDs":[null]}}'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('provideReadableRevisionCounts')]
	public function testReadsARevisionCount(string $payload, int $count): void {
		$this->assertSame($count, $this->clientWithResponse($this->response(200, $payload))->getRevisionsCount('pad'));
	}

	/** @return array<string,array{string, int}> */
	public static function provideReadableRevisionCounts(): array {
		return [
			'a number' => ['{"code":0,"data":{"revisions":12}}', 12],
			'digits' => ['{"code":0,"data":{"revisions":"7"}}', 7],
			// A count, not a missing one: a pad nobody has written in yet.
			'zero' => ['{"code":0,"data":{"revisions":0}}', 0],
		];
	}

	/**
	 * Not 0: a sweep holds a pad to its file's snapshot revision, and 0
	 * reads as a pad behind it - one it lets go of. An answer without a
	 * count says nothing about the pad.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('provideUnreadableRevisionCounts')]
	public function testGetRevisionsCountRefusesAnAnswerWithoutACount(string $payload): void {
		$client = $this->clientWithResponse($this->response(200, $payload));
		$this->expectException(EtherpadClientException::class);
		$client->getRevisionsCount('pad');
	}

	/** @return array<string,array{string}> */
	public static function provideUnreadableRevisionCounts(): array {
		return [
			'no revisions field' => ['{"code":0,"data":{}}'],
			'null data' => ['{"code":0,"data":null}'],
			'revisions is null' => ['{"code":0,"data":{"revisions":null}}'],
			'revisions is not a number' => ['{"code":0,"data":{"revisions":"many"}}'],
			'revisions is negative' => ['{"code":0,"data":{"revisions":-1}}'],
			'revisions is a fraction' => ['{"code":0,"data":{"revisions":1.5}}'],
		];
	}

	/**
	 * Measured: `/api` answers `{"currentVersion":"1.3.1"}` on both Etherpad
	 * 2.7.3 and 3.3.3, so the API version cannot tell the two apart.
	 * `/health` reports the release, and it needs no api key.
	 */
	public function testDetectsTheReleaseFromHealth(): void {
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"status":"pass","releaseId":"3.3.3"}'),
			$captured,
		);

		self::assertSame('3.3.3', $client->detectReleaseVersion('https://pad.example.test'));
		self::assertSame('GET', $captured['method']);
		self::assertStringEndsWith('/health', (string)$captured['url']);
		self::assertStringNotContainsString('apikey', (string)$captured['url']);
		// Asked on the open path, and nothing depends on the answer, so a pad
		// server that accepts the connection and then says nothing must not
		// hold an open for the full request timeout.
		self::assertSame(3, $captured['options']['timeout']);
	}

	/**
	 * A caller that says how long it may take gets exactly that, from every
	 * wrapper that takes a timeout. One that left the argument out would run
	 * at the full default: the open path would wait longer than it asked,
	 * a sweep would overrun its budget, and the callers' tests would not
	 * notice, because they assert against a mocked client.
	 *
	 * @param \Closure(EtherpadClient, int): mixed $call
	 * @param array<array-key, mixed> $data
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('callsWithATimeout')]
	public function testPassesACallersTimeoutThroughToTheRequest(\Closure $call, array $data): void {
		$captured = null;
		$client = $this->clientWithResponse($this->response(200, (string)json_encode(['code' => 0, 'data' => $data])), $captured);

		$call($client, 4);

		self::assertSame(4, $captured['options']['timeout']);
	}

	/** @return array<string, array{\Closure(EtherpadClient, int): mixed, array<array-key, mixed>}> */
	public static function callsWithATimeout(): array {
		return [
			'getText' => [static fn (EtherpadClient $c, int $t): mixed => $c->getText('pad-1', $t), ['text' => 'pad text']],
			'getHTML' => [static fn (EtherpadClient $c, int $t): mixed => $c->getHTML('pad-1', $t), ['html' => '<p>pad</p>']],
			'getRevisionsCount' => [static fn (EtherpadClient $c, int $t): mixed => $c->getRevisionsCount('pad-1', $t), ['revisions' => 3]],
			'deletePad' => [static fn (EtherpadClient $c, int $t): mixed => $c->deletePad('pad-1', $t), []],
			'listPads' => [static fn (EtherpadClient $c, int $t): mixed => $c->listPads('g.group', $t), ['padIDs' => []]],
			'deleteGroup' => [static fn (EtherpadClient $c, int $t): mixed => $c->deleteGroup('g.group', $t), []],
			'deleteSession' => [static fn (EtherpadClient $c, int $t): mixed => $c->deleteSession('s.aaaaaaaaaaaaaaaa', $t), []],
			'listSessionsOfAuthor' => [static fn (EtherpadClient $c, int $t): mixed => $c->listSessionsOfAuthor('a.alice', $t), []],
			'listSessionsOfGroup' => [static fn (EtherpadClient $c, int $t): mixed => $c->listSessionsOfGroup('g.group', $t), []],
			'assertAnswering' => [static fn (EtherpadClient $c, int $t): mixed => $c->assertAnswering($t), []],
		];
	}

	/**
	 * Guzzle reads `timeout => 0` as no timeout at all, so the one
	 * parameter whose purpose is bounding time must never be able to
	 * unbound a call. Clamped at both ends: no housekeeping call gets more
	 * patience than the ones a user waits on either.
	 */
	public function testClampsATimeoutThatWouldRemoveTheBound(): void {
		foreach ([0 => 1, -5 => 1, 900 => 15] as $asked => $expected) {
			$captured = null;
			$client = $this->clientWithResponse(
				$this->response(200, '{"code":0,"data":null}'),
				$captured,
			);

			$client->deleteSession('s.aaaaaaaaaaaaaaaa', $asked);

			self::assertSame($expected, $captured['options']['timeout'], "asked for {$asked}");
		}
	}

	/**
	 * An id the index lists but Etherpad cannot describe is counted, not
	 * quietly dropped.
	 *
	 * Etherpad's listing walks the author index and answers null for a key
	 * whose session record is gone. Such a key still costs a lookup on
	 * every listing, and `deleteSession` will not take it — it answers that
	 * it does not exist. Swallowing them would let a collector report a
	 * clean sweep while the index it exists to shrink stayed as long.
	 */
	public function testCountsTheIndexEntriesEtherpadCannotDescribe(): void {
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, json_encode(['code' => 0, 'data' => [
				's.aaaaaaaaaaaaaaaa' => ['groupID' => 'g.aaaaaaaaaaaaaaaa', 'validUntil' => 4102444800],
				's.bbbbbbbbbbbbbbbb' => null,
				's.cccccccccccccccc' => null,
				// Malformed rather than null, and just as unusable: still a
				// key the listing walks that no delete will take.
				's.dddddddddddddddd' => ['validUntil' => 4102444800],
				's.eeeeeeeeeeeeeeee' => ['groupID' => 'g.aaaaaaaaaaaaaaaa'],
				's.ffffffffffffffff' => [],
			]])),
			$captured,
		);

		$sessions = $client->listSessionsOfAuthor('a.aaaaaaaaaaaaaaaa', null, $unreadable);

		self::assertSame(['s.aaaaaaaaaaaaaaaa'], array_keys($sessions));
		self::assertSame(5, $unreadable);
	}

	/** The calls that do matter keep the full patience. */
	public function testApiCallsKeepTheFullRequestTimeout(): void {
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"groupID":"g.aaa"}}'),
			$captured,
		);

		$client->createGroup();

		self::assertSame(15, $captured['options']['timeout']);
	}

	public function testDetectsAReleaseWithASuffix(): void {
		$client = $this->clientWithResponse($this->response(200, '{"status":"pass","releaseId":"3.0.0-beta.1"}'));
		self::assertSame('3.0.0-beta.1', $client->detectReleaseVersion('https://pad.example.test'));
	}

	/**
	 * Refuses rather than guessing. The caller falls back to the last known
	 * release, and without one to a cookie the browser can read.
	 *
	 * @param string $payload
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('provideUnreadableHealthAnswers')]
	public function testRefusesAHealthAnswerItCannotRead(string $payload): void {
		$client = $this->clientWithResponse($this->response(200, $payload));
		$this->expectException(EtherpadClientException::class);
		$client->detectReleaseVersion('https://pad.example.test');
	}

	/** @return array<string,array{string}> */
	public static function provideUnreadableHealthAnswers(): array {
		return [
			'no releaseId' => ['{"status":"pass"}'],
			'releaseId is not a string' => ['{"status":"pass","releaseId":3}'],
			'releaseId is empty' => ['{"status":"pass","releaseId":""}'],
			'releaseId is not a version' => ['{"status":"pass","releaseId":"latest"}'],
			'not an object' => ['[]'],
			// The one an unanchored prefix match lets through. This is
			// written to app config, read on every protected open and
			// rendered to an admin.
			'a version and then a megabyte' => ['{"status":"pass","releaseId":"3.3.3' . str_repeat('x', 1024) . '"}'],
			'a version and then prose' => ['{"status":"pass","releaseId":"3.3.3 (nightly build of something)"}'],
		];
	}

	/** Etherpad answered, and refused: its own type, since trying again gives the same answer. */
	public function testApiCallThrowsOnNonZeroApiCode(): void {
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":1,"message":"groupID does not exist"}')
		);
		$this->expectException(EtherpadRefusedException::class);
		$this->expectExceptionMessage('Etherpad API error (createGroup): groupID does not exist');
		$client->createGroup();
	}

	/** No answer it could have meant is no refusal: trying again may help. */
	public function testApiCallThrowsOnInvalidJson(): void {
		$client = $this->clientWithResponse($this->response(200, '<html>nope</html>'));
		try {
			$client->createGroup();
			$this->fail('No exception.');
		} catch (EtherpadClientException $e) {
			$this->assertNotInstanceOf(EtherpadRefusedException::class, $e);
			$this->assertSame('Invalid JSON response from Etherpad API.', $e->getMessage());
		}
	}

	public function testApiCallSurfacesHttpErrorStatusAsCause(): void {
		// A 401 without Etherpad's own answer came from something in front of
		// it: an HTTP error, wrapped as "request failed: <method>" with the
		// status in the cause.
		$client = $this->clientWithResponse($this->response(401, 'Unauthorized'));

		try {
			$client->createGroup();
			$this->fail('Expected EtherpadClientException.');
		} catch (EtherpadClientException $e) {
			$this->assertSame('Etherpad API request failed: createGroup', $e->getMessage());
			$this->assertNotInstanceOf(EtherpadKeyRejectedException::class, $e);
			$this->assertInstanceOf(EtherpadClientException::class, $e->getPrevious());
			$this->assertStringContainsString('Etherpad API HTTP error (401)', $e->getPrevious()->getMessage());
		}
	}

	/**
	 * Etherpad's own code 4 - under HTTP 401 as Etherpad sends it, or 403,
	 * also where Nextcloud's client throws on the status and hands the
	 * answer back, or under a success status - is a rejected key, with the
	 * messages the connection test's hints read. A 401 or 403 without it - a
	 * proxy's page, another JSON - is not, nor another status or code. No
	 * message holds the key, even where the answer quotes it.
	 */
	public function testARejectedKeyIsToldApart(): void {
		$rejected = '{"code":4,"message":"no or wrong API Key","data":null}';
		foreach ([401, 403] as $status) {
			$e = $this->failureOf(fn () => $this->clientWithResponse($this->response($status, $rejected))->createGroup());
			$this->assertInstanceOf(EtherpadKeyRejectedException::class, $e, (string)$status);
			$this->assertSame('Etherpad API request failed: createGroup', $e->getMessage());
			$this->assertInstanceOf(EtherpadKeyRejectedException::class, $e->getPrevious());
			$this->assertSame('Etherpad API HTTP error (' . $status . ')', $e->getPrevious()?->getMessage());
		}

		$http = $this->createMock(IClient::class);
		$http->method('request')->willThrowException(new \RuntimeException('401 Unauthorized'));
		$http->method('getResponseFromThrowable')->willReturn($this->response(401, $rejected));
		$this->assertInstanceOf(EtherpadKeyRejectedException::class, $this->failureOf(fn () => $this->clientWith($http)->createGroup()));

		// A call with a size cap reads the answer from its sink.
		$this->assertInstanceOf(EtherpadKeyRejectedException::class, $this->failureOf(fn () => $this->clientWithResponse($this->response(401, $rejected))->getHTMLForPreview('pad-1')));

		foreach ([
			[401, '<html><body>Sign in</body></html>'],
			[401, '{"message":"Unauthorized"}'],
			[403, '<html><body>Forbidden</body></html>'],
			[403, '{"code":403,"message":"Forbidden"}'],
		] as [$status, $body]) {
			$proxied = $this->failureOf(fn () => $this->clientWithResponse($this->response($status, $body))->createGroup());
			$this->assertNotInstanceOf(EtherpadKeyRejectedException::class, $proxied, $status . ' ' . $body);
			$this->assertSame('Etherpad API HTTP error (' . $status . ')', $proxied->getPrevious()?->getMessage());
		}

		$e = $this->failureOf(fn () => $this->clientWithResponse($this->response(200, $rejected))->createGroup());
		$this->assertInstanceOf(EtherpadKeyRejectedException::class, $e);
		$this->assertSame('Etherpad API error (createGroup): no or wrong API Key', $e->getMessage());
		$quoted = $this->failureOf(fn () => $this->clientWithResponse($this->response(200, '{"code":4,"message":"invalid apikey stored-key","data":null}'))->createGroup());
		$this->assertSame('Etherpad API error (createGroup): invalid apikey ***', $quoted->getMessage());

		foreach ([[500, $rejected], [404, '{"code":3,"message":"no such function","data":null}']] as [$status, $body]) {
			$this->assertNotInstanceOf(EtherpadKeyRejectedException::class, $this->failureOf(fn () => $this->clientWithResponse($this->response($status, $body))->createGroup()), (string)$status);
		}
		$other = $this->failureOf(fn () => $this->clientWithResponse($this->response(200, '{"code":1,"message":"groupID does not exist","data":null}'))->createGroup());
		$this->assertInstanceOf(EtherpadRefusedException::class, $other);
	}

	private function failureOf(\Closure $call): EtherpadClientException {
		try {
			$call();
		} catch (EtherpadClientException $e) {
			return $e;
		}
		$this->fail('The call should fail.');
	}

	public function testDetectApiVersionUsesGetAgainstApiEndpointWithoutBody(): void {
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"currentVersion":"1.3.0"}'),
			$captured,
		);

		$this->assertSame('1.3.0', $client->detectApiVersion('https://pad.example.test/'));

		$this->assertNotNull($captured);
		$this->assertSame('GET', $captured['method']);
		$this->assertSame('https://pad.example.test/api', $captured['url']);
		$this->assertSame(EtherpadClient::REQUEST_TIMEOUT_SECONDS, $captured['options']['timeout']);
		// Detection is a plain GET: no request body, redirects disabled.
		$this->assertArrayNotHasKey('body', $captured['options']);
		$this->assertSame(['max' => 0], $captured['options']['allow_redirects']);
		$this->assertSame(['allow_local_address' => true], $captured['options']['nextcloud']);
	}

	/**
	 * A throwable that carries no response is a transport failure: named
	 * with its cause, and the body stream the request had opened closed.
	 */
	public function testApiCallWrapsTransportFailure(): void {
		$body = null;
		$http = $this->createMock(IClient::class);
		$http->method('request')->willReturnCallback(static function (string $method, string $url, array $options) use (&$body): IResponse {
			$body = $options['body'];
			throw new \RuntimeException('connection refused');
		});
		$http->method('getResponseFromThrowable')->willThrowException(new \RuntimeException('no response'));

		try {
			$this->clientWith($http)->createGroup();
			$this->fail('Not refused.');
		} catch (EtherpadClientException $e) {
			$this->assertSame('Etherpad API request failed: createGroup', $e->getMessage());
			$this->assertSame('Etherpad transport error: connection refused', $e->getPrevious()?->getMessage());
			$this->assertFalse(is_resource($body), 'the body stream should be closed');
		}
	}

	/**
	 * Construct the client for the URL-helper tests with a never-called HTTP
	 * client (these tests do not make requests).
	 */
	private function client(IConfig $config): EtherpadClient {
		return new EtherpadClient(
			$config,
			$this->createMock(AdminSettingsRepository::class),
			$this->createMock(IClientService::class),
		);
	}

	/**
	 * The cap has to be part of the transfer. Nextcloud pins Guzzle's
	 * CurlHandler, which buffers the whole body into a temp stream before
	 * anything of ours could look at its size — so the refusal comes out
	 * of the sink, while the bytes are still arriving.
	 */
	public function testPreviewExportRefusesABodyPastTheCap(): void {
		$this->expectException(EtherpadTooLargeException::class);
		$this->clientWritingIntoTheSink(EtherpadClient::PREVIEW_MAX_BYTES + 1)->getHTMLForPreview('pad-1');
	}

	/** The reader is told the pad is large, not that the request failed. */
	public function testTooLargeSurvivesTheClientsOwnErrorWrapping(): void {
		try {
			$this->clientWritingIntoTheSink(EtherpadClient::PREVIEW_MAX_BYTES + 1)->getHTMLForPreview('pad-1');
			$this->fail('expected the oversized export to be refused');
		} catch (EtherpadClientException $e) {
			$this->assertInstanceOf(EtherpadTooLargeException::class, $e);
			$this->assertStringNotContainsString('Etherpad API request failed', $e->getMessage());
		}
	}

	/**
	 * A sweep's session listing is read up to the cap and refused past it:
	 * read whole, it could take a background job's memory with it.
	 */
	public function testASweepsSessionListingPastTheCapIsRefused(): void {
		$cap = EtherpadClient::SESSION_LISTING_MAX_BYTES;
		$listings = [
			'an author\'s' => static fn (EtherpadClient $client): array => $client->listSessionsOfAuthor('a.author', null, $unreadable, $cap),
			'a group\'s' => static fn (EtherpadClient $client): array => $client->listSessionsOfGroup('g.AAAAAAAAAAAAAAAA', null, $unreadable, $cap),
		];
		foreach ($listings as $case => $list) {
			try {
				$list($this->clientWritingIntoTheSink(EtherpadClient::SESSION_LISTING_MAX_BYTES + 1));
				$this->fail($case . ': the oversized listing was read');
			} catch (EtherpadTooLargeException) {
				$this->addToAssertionCount(1);
			}
			try {
				$list($this->clientWritingIntoTheSink(EtherpadClient::SESSION_LISTING_MAX_BYTES));
				$this->fail($case . ': a body of no JSON was taken for a listing');
			} catch (EtherpadClientException $e) {
				$this->assertNotInstanceOf(EtherpadTooLargeException::class, $e, $case . ': at the cap the body is read');
			}
		}
	}

	/** A revoke's listing is read whole: ending access is worth the memory. */
	public function testARevokesSessionListingIsReadWhole(): void {
		$body = (string)json_encode(['code' => 0, 'data' => ['s.1' => ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => 2_000_000_000]]]);
		foreach (['listSessionsOfAuthor' => 'a.author', 'listSessionsOfGroup' => 'g.AAAAAAAAAAAAAAAA'] as $method => $id) {
			$captured = null;
			$sessions = $this->clientWithResponse($this->response(200, $body), $captured)->$method($id);
			$this->assertSame(['s.1'], array_keys($sessions), $method);
			$this->assertArrayNotHasKey('sink', $captured['options'] ?? [], $method);
		}
	}

	public function testPreviewExportAsksForTheCappedSink(): void {
		$captured = null;
		$body = (string)json_encode(['code' => 0, 'data' => ['html' => '<p>small</p>']]);

		$this->assertSame('<p>small</p>', $this->clientWithResponse($this->response(200, $body), $captured)->getHTMLForPreview('pad-1'));
		$this->assertInstanceOf(BoundedSinkStream::class, $captured['options']['sink'] ?? null);
		$this->assertArrayNotHasKey('stream', $captured['options'], 'the pinned curl handler ignores it');
	}

	/** An uncapped call must not grow a sink it never asked for. */
	public function testOrdinaryCallsGetNoSink(): void {
		$captured = null;
		$body = (string)json_encode(['code' => 0, 'data' => ['text' => 'x']]);

		$this->clientWithResponse($this->response(200, $body), $captured)->getText('pad-1');

		$this->assertArrayNotHasKey('sink', $captured['options']);
	}

	/**
	 * An answer without the field is not an empty pad. Coercing it to `''`
	 * shows one as empty, and a sync writes that over the stored copy.
	 *
	 * @param array<string,mixed> $data
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('incompleteApiAnswers')]
	public function testAnIncompleteApiAnswerIsRejected(array $data, string $call): void {
		$client = $this->clientWithResponse($this->response(200, (string)json_encode(['code' => 0, 'data' => $data])));

		$this->expectException(EtherpadClientException::class);
		$client->$call('pad-1');
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public static function incompleteApiAnswers(): array {
		return [
			'getHTML without html' => [[], 'getHTML'],
			'getHTML with a non-string html' => [['html' => 42], 'getHTML'],
			'getText without text' => [[], 'getText'],
			'getText with a non-string text' => [['text' => ['a']], 'getText'],
		];
	}

	/** Drives the sink the way the HTTP client would: chunk by chunk. */
	private function clientWritingIntoTheSink(int $bytes): EtherpadClient {
		$response = $this->response(200, '');
		$http = $this->createMock(IClient::class);
		$http->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) use ($response, $bytes): IResponse {
				$sink = $options['sink'] ?? null;
				$this->assertInstanceOf(BoundedSinkStream::class, $sink);
				foreach (str_split(str_repeat('a', $bytes), 8192) as $chunk) {
					$sink->write($chunk);
				}
				return $response;
			}
		);

		return $this->clientWith($http);
	}

	/**
	 * The probe behind the admin connection test. Asserted here because the
	 * settings page renders the address separately from the call that goes
	 * out, and only a test over both keeps them the same.
	 */
	public function testAssertApiKeyAcceptedProbesTheAdvertisedUrlWithTheKey(): void {
		$captured = null;
		$client = $this->clientWithResponse($this->response(200, '{"code":0,"message":"ok","data":null}'), $captured);

		$client->assertApiKeyAccepted('https://pad.example.test/', new ApiKey('probe-key'), '1.3.0');

		$this->assertSame('POST', $captured['method']);
		$this->assertSame(
			EtherpadClient::buildApiUrl('https://pad.example.test/', '1.3.0', EtherpadClient::API_KEY_PROBE_METHOD),
			$captured['url'],
		);
		$this->assertStringContainsString('/api/1.3.0/checkToken', $captured['url']);
		$this->assertStringNotContainsString('probe-key', $captured['url']);
		$this->assertStringContainsString('apikey=probe-key', self::bodyOf($captured));
	}

	public function testAssertApiKeyAcceptedThrowsWhenEtherpadRejectsTheKey(): void {
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":4,"message":"no or wrong API Key","data":null}'),
		);

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('no or wrong API Key');
		$client->assertApiKeyAccepted('https://pad.example.test', new ApiKey('wrong-key'), '1.3.0');
	}

	public function testBuildApiUrlTrimsTheHostAndVersionTheSameWayACallDoes(): void {
		$captured = null;
		$client = $this->clientWithResponse($this->response(200, '{"code":0,"data":null}'), $captured);

		$client->assertApiKeyAccepted('  https://pad.example.test/  ', new ApiKey('probe-key'), ' 1.3.0 ');

		$this->assertSame($captured['url'], EtherpadClient::buildApiUrl('  https://pad.example.test/  ', ' 1.3.0 ', 'checkToken'));
		$this->assertSame('https://pad.example.test/api/1.3.0/checkToken', $captured['url']);
	}

	/**
	 * Every wrapper, since each spells out its method, its parameters and
	 * the field of the answer it reads by hand.
	 *
	 * @param \Closure(EtherpadClient): mixed $call
	 * @param array<string, string> $params
	 * @param array<string, mixed> $data
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('calls')]
	public function testEachCallSendsItsMethodAndParameters(\Closure $call, string $method, array $params, array $data, mixed $expected): void {
		$captured = null;
		$client = $this->clientWithResponse($this->response(200, (string)json_encode(['code' => 0, 'data' => $data])), $captured);

		$this->assertSame($expected, $call($client));

		$this->assertSame(['POST', 'http://etherpad.internal:9001/api/1.3.0/' . $method], [$captured['method'], $captured['url']]);
		parse_str(self::bodyOf($captured), $sent);
		$this->assertSame($params + ['apikey' => 'stored-key'], $sent);
		$this->assertSame('application/x-www-form-urlencoded', $captured['options']['headers']['Content-Type']);
		$this->assertSame('application/json', $captured['options']['headers']['Accept']);
	}

	/** @return array<string, array{\Closure(EtherpadClient): mixed, string, array<string, string>, array<string, mixed>, mixed}> */
	public static function calls(): array {
		return [
			'getText' => [static fn (EtherpadClient $c): mixed => $c->getText('pad-1'), 'getText', ['padID' => 'pad-1'], ['text' => 'pad text'], 'pad text'],
			'getHTML' => [static fn (EtherpadClient $c): mixed => $c->getHTML('pad-1'), 'getHTML', ['padID' => 'pad-1'], ['html' => '<p>pad</p>'], '<p>pad</p>'],
			'getHTMLForPreview' => [static fn (EtherpadClient $c): mixed => $c->getHTMLForPreview('pad-1'), 'getHTML', ['padID' => 'pad-1'], ['html' => '<p>pad</p>'], '<p>pad</p>'],
			'getRevisionsCount' => [static fn (EtherpadClient $c): mixed => $c->getRevisionsCount('pad-1'), 'getRevisionsCount', ['padID' => 'pad-1'], ['revisions' => 3], 3],
			'setText' => [static fn (EtherpadClient $c): mixed => $c->setText('pad-1', 'new text'), 'setText', ['padID' => 'pad-1', 'text' => 'new text'], [], null],
			'setHTML' => [static fn (EtherpadClient $c): mixed => $c->setHTML('pad-1', '<p>new</p>'), 'setHTML', ['padID' => 'pad-1', 'html' => '<p>new</p>'], [], null],
			'deletePad' => [static fn (EtherpadClient $c): mixed => $c->deletePad('pad-1'), 'deletePad', ['padID' => 'pad-1'], [], null],
			'createPad' => [static fn (EtherpadClient $c): mixed => $c->createPad('pad-1'), 'createPad', ['padID' => 'pad-1'], [], null],
			'listPads' => [static fn (EtherpadClient $c): mixed => $c->listPads('g.group'), 'listPads', ['groupID' => 'g.group'], ['padIDs' => ['g.group$a']], ['g.group$a']],
			'deleteGroup' => [static fn (EtherpadClient $c): mixed => $c->deleteGroup('g.group'), 'deleteGroup', ['groupID' => 'g.group'], [], null],
			'createGroupPad' => [static fn (EtherpadClient $c): mixed => $c->createGroupPad('g.group', 'notes'), 'createGroupPad', ['groupID' => 'g.group', 'padName' => 'notes'], ['padID' => 'g.group$notes'], 'g.group$notes'],
			'createAuthorIfNotExistsFor' => [static fn (EtherpadClient $c): mixed => $c->createAuthorIfNotExistsFor('alice', 'Alice'), 'createAuthorIfNotExistsFor', ['authorMapper' => 'alice', 'name' => 'Alice'], ['authorID' => 'a.alice'], 'a.alice'],
			'createSession' => [static fn (EtherpadClient $c): mixed => $c->createSession('g.group', 'a.alice', 1800000000), 'createSession', ['groupID' => 'g.group', 'authorID' => 'a.alice', 'validUntil' => '1800000000'], ['sessionID' => 's.one'], 's.one'],
			'deleteSession' => [static fn (EtherpadClient $c): mixed => $c->deleteSession('s.one'), 'deleteSession', ['sessionID' => 's.one'], [], null],
			'getReadOnlyPadUrl' => [static fn (EtherpadClient $c): mixed => $c->getReadOnlyPadUrl('pad-1'), 'getReadOnlyID', ['padID' => 'pad-1'], ['readOnlyID' => 'r.read'], 'https://pad.example.test/p/r.read'],
			'assertAnswering' => [static fn (EtherpadClient $c): mixed => $c->assertAnswering(), EtherpadClient::API_KEY_PROBE_METHOD, [], [], null],
		];
	}

	/** An id a create or a lookup should hand back, missing: no call goes on with an empty one. */
	#[\PHPUnit\Framework\Attributes\DataProvider('idsNotReturned')]
	public function testRefusesAnAnswerWithoutTheIdItCreated(\Closure $call, string $message): void {
		$client = $this->clientWithResponse($this->response(200, '{"code":0,"data":{}}'));

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage($message);
		$call($client);
	}

	/** @return array<string, array{\Closure(EtherpadClient): mixed, string}> */
	public static function idsNotReturned(): array {
		return [
			'a group' => [static fn (EtherpadClient $c): mixed => $c->createGroup(), 'Etherpad did not return groupID.'],
			'a group pad' => [static fn (EtherpadClient $c): mixed => $c->createGroupPad('g.group', 'notes'), 'Etherpad did not return group pad ID.'],
			'an author' => [static fn (EtherpadClient $c): mixed => $c->createAuthorIfNotExistsFor('alice', 'Alice'), 'Etherpad did not return authorID.'],
			'a session' => [static fn (EtherpadClient $c): mixed => $c->createSession('g.group', 'a.alice', 1800000000), 'Etherpad did not return sessionID.'],
			'a read-only id' => [static fn (EtherpadClient $c): mixed => $c->getReadOnlyPadUrl('pad-1'), 'Etherpad did not return readOnlyID.'],
		];
	}

	/** Code 0 is Etherpad's success, so a missing code is not read as 0. */
	public function testRefusesAnAnswerWithoutACode(): void {
		$client = $this->clientWithResponse($this->response(200, '{"data":{"groupID":"g.abc123"}}'));

		$this->expectException(EtherpadRefusedException::class);
		$this->expectExceptionMessage('Etherpad API error (createGroup): Unknown Etherpad API error');
		$client->createGroup();
	}

	public function testReadsEverySessionAListingNames(): void {
		$client = $this->clientWithResponse($this->response(200, (string)json_encode(['code' => 0, 'data' => [
			's.one' => ['groupID' => 'g.a', 'authorID' => 'a.x', 'validUntil' => 99],
			's.two' => ['groupID' => 'g.b', 'authorID' => 'a.x', 'validUntil' => 100],
		]])));

		$this->assertSame(['s.one' => ['groupID' => 'g.a', 'validUntil' => 99], 's.two' => ['groupID' => 'g.b', 'validUntil' => 100]], $client->listSessionsOfAuthor('a.x'));
	}

	/** The API version from the first of its fields that holds text; anything there but `<major>.<minor>.<patch>` is no version. */
	#[\PHPUnit\Framework\Attributes\DataProvider('apiVersionAnswers')]
	public function testDetectsTheApiVersion(string $answer, ?string $version): void {
		$captured = null;
		$client = $this->clientWithResponse($this->response(200, $answer), $captured);

		try {
			$detected = $client->detectApiVersion(' https://pad.example.test/ ');
		} catch (EtherpadClientException $e) {
			$this->assertSame('Could not detect Etherpad API version.', $e->getMessage());
			$detected = null;
		}

		$this->assertSame($version, $detected);
		$this->assertSame('https://pad.example.test/api', $captured['url']);
	}

	/** @return array<string, array{string, ?string}> */
	public static function apiVersionAnswers(): array {
		return [
			'the current version' => ['{"currentVersion":"1.3.0"}', '1.3.0'],
			'the first field with text' => ['{"currentVersion":"1.3.0","apiVersion":"9.9.9"}', '1.3.0'],
			'past an empty field' => ['{"currentVersion":"","apiVersion":"1.2.15"}', '1.2.15'],
			'past a field of another type' => ['{"currentVersion":5,"version":"1.2.15"}', '1.2.15'],
			'with spaces around it' => ['{"currentVersion":" 1.3.0 "}', '1.3.0'],
			'two parts' => ['{"currentVersion":"1.3"}', null],
			'a prefix' => ['{"currentVersion":"v1.3.0"}', null],
			'a suffix' => ['{"currentVersion":"1.3.0-beta"}', null],
			'no JSON' => ['<html>Etherpad</html>', null],
		];
	}

	/** The release from /health of the host given, or of the API host for none; trimmed, without a prefix. */
	public function testDetectsTheReleaseOfTheHostGiven(): void {
		$captured = null;
		$this->assertSame('2.7.3', $this->clientWithResponse($this->response(200, '{"releaseId":" 2.7.3 "}'), $captured)->detectReleaseVersion(' https://other.example.test/ '));
		$this->assertSame('https://other.example.test/health', $captured['url']);

		$this->clientWithResponse($this->response(200, '{"releaseId":"2.7.3"}'), $captured)->detectReleaseVersion('  ');
		$this->assertSame('http://etherpad.internal:9001/health', $captured['url']);

		$this->expectException(EtherpadClientException::class);
		$this->clientWithResponse($this->response(200, '{"releaseId":"v2.7.3"}'))->detectReleaseVersion('');
	}

	/** 400 is the first error status, for an API call and for the public endpoints alike. */
	public function testTakesStatus400ForAnError(): void {
		try {
			$this->clientWithResponse($this->response(400, '{"code":0,"data":{"groupID":"g.abc123"}}'))->createGroup();
			$this->fail('API call: taken');
		} catch (EtherpadClientException $e) {
			$this->assertSame('Etherpad API HTTP error (400)', $e->getPrevious()?->getMessage());
		}

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('HTTP error (400)');
		$this->clientWithResponse($this->response(400, '{"releaseId":"2.7.3"}'))->detectReleaseVersion('');
	}

	public function testTakesABlankHostVersionOrKeyForNone(): void {
		$captured = null;
		$this->clientWithResponse($this->response(200, '{"code":0,"data":null}'), $captured)->assertApiKeyAccepted('  ', new ApiKey(''), '  ');

		$this->assertSame('http://etherpad.internal:9001/api/1.3.0/checkToken', $captured['url']);
		$this->assertStringContainsString('apikey=stored-key', self::bodyOf($captured));
	}

	public function testRefusesACallWithoutAConfiguredKey(): void {
		$client = new EtherpadClient($this->configForApi(), $this->repositoryWithKey(''), $this->createMock(IClientService::class));

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Etherpad API key is not configured.');
		$client->createGroup();
	}

	public function testRefusesAPadAddressWithoutAConfiguredHost(): void {
		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Etherpad host is not configured.');
		$this->client($this->configWithHost(''))->buildPadUrl('pad-1');
	}

	/** Nextcloud's client throws on an error status; the status is read from the response that throwable carries. */
	public function testReadsTheStatusFromAThrownError(): void {
		$http = $this->createMock(IClient::class);
		$http->method('request')->willThrowException(new \RuntimeException('404 Not Found'));
		$http->method('getResponseFromThrowable')->willReturn($this->response(404, ''));

		try {
			$this->clientWith($http)->createGroup();
			$this->fail('Not refused.');
		} catch (EtherpadClientException $e) {
			$this->assertSame('Etherpad API HTTP error (404)', $e->getPrevious()?->getMessage());
		}
	}

	/** An origin needs a scheme and a host; a port is kept unless it is its scheme's own. */
	#[\PHPUnit\Framework\Attributes\DataProvider('origins')]
	public function testNormalizesAnOrigin(string $url, string $origin): void {
		$this->assertSame($origin, $this->client($this->configWithHost(''))->normalizeOrigin($url));
	}

	/** @return array<string, array{string, string}> */
	public static function origins(): array {
		return [
			'no scheme' => ['//pad.example.test', ''],
			'no host' => ['mailto:someone@example.test', ''],
			'the https port on http' => ['http://pad.example.test:443', 'http://pad.example.test:443'],
			'the http port on https' => ['https://pad.example.test:80', 'https://pad.example.test:80'],
			'the https port on https' => ['HTTPS://Pad.Example.Test:443/p/x', 'https://pad.example.test'],
			'the http port on http' => ['http://pad.example.test:80', 'http://pad.example.test'],
			'another port' => ['https://pad.example.test:9001', 'https://pad.example.test:9001'],
			// Not https only, unlike a public pad link: an admin may run Etherpad on http in a private network.
			'http' => ['http://pad.internal.lan', 'http://pad.internal.lan'],
		];
	}

	/**
	 * The request body as text. It travels as a stream handle so it cannot
	 * be printed from a stack frame, which is also why casting it to string
	 * would yield the handle rather than what was sent.
	 *
	 * @param array{method:string,url:string,options:array<string,mixed>} $captured
	 */
	private static function bodyOf(array $captured): string {
		$body = $captured['options']['body'];
		self::assertIsResource($body);
		rewind($body);
		return (string)stream_get_contents($body);
	}

	/**
	 * Build a client whose single HTTP call returns the given response.
	 * Optionally captures the outgoing [method, url, options].
	 *
	 * @param array{method:string,url:string,options:array<string,mixed>}|null $captured
	 */
	private function clientWithResponse(IResponse $response, ?array &$captured = null): EtherpadClient {
		$http = $this->createMock(IClient::class);
		$http->method('request')->willReturnCallback(
			static function (string $method, string $url, array $options) use ($response, &$captured): IResponse {
				$captured = ['method' => $method, 'url' => $url, 'options' => $options];
				// A sink is where the client puts the body, so a double that
				// ignores it would let a capped call read nothing.
				$sink = $options['sink'] ?? null;
				if ($sink instanceof BoundedSinkStream) {
					$sink->write((string)$response->getBody());
				}
				return $response;
			}
		);

		return $this->clientWith($http);
	}

	private function clientWith(IClient $http): EtherpadClient {
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);
		return new EtherpadClient($this->configForApi(), $this->repositoryWithKey('stored-key'), $service);
	}

	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	private function repositoryWithKey(string $key): AdminSettingsRepository {
		$repository = $this->createMock(AdminSettingsRepository::class);
		$repository->method('getApiKey')->willReturn($key);
		return $repository;
	}

	/**
	 * The API host differs from the public one, and the API version from the
	 * default, so a test sees which of them a call takes.
	 */
	private function configForApi(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName !== 'etherpad_nextcloud') {
					return $default;
				}
				return match ($key) {
					'etherpad_host' => 'https://pad.example.test',
					'etherpad_api_host' => 'http://etherpad.internal:9001',
					'etherpad_api_version' => '1.3.0',
					default => $default,
				};
			}
		);
		return $config;
	}

	private function configWithHost(string $host): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use ($host): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'etherpad_host') {
					return $host;
				}
				return $default;
			}
		);
		return $config;
	}
}
