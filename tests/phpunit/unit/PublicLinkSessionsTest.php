<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PublicLinkCache;
use OCA\EtherpadNextcloud\Service\PublicLinkSessions;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The session a public link hands out again for a while: the cache
 * points, Etherpad decides, and a cache that fails keeps nothing.
 */
class PublicLinkSessionsTest extends TestCase {
	private const LINK = 'public-share:a-share-token';
	private const GROUP = 'g.ABCDEFGHIJKLMNOP';
	private const AUTHOR = 'a.public';
	/** When a session made now runs out: three hours, kept for one. */
	private const NEW_UNTIL = FixedClock::NOW + 10800;

	/** @var \ArrayObject<string, array{value: mixed, ttl: int}> */
	private \ArrayObject $held;
	/** @var \ArrayObject<int, string> */
	private \ArrayObject $made;

	protected function setUp(): void {
		$this->held = new \ArrayObject();
		$this->made = new \ArrayObject();
	}

	public function testAKeptSessionEtherpadConfirmsIsHandedOutAgain(): void {
		$sessions = $this->sessions($this->client(['groupID' => self::GROUP, 'authorID' => self::AUTHOR, 'validUntil' => self::NEW_UNTIL - 1800]));
		$this->open($sessions);

		$this->assertSame(['sessionId' => 's.made1', 'validUntil' => self::NEW_UNTIL - 1800], $this->open($sessions));
		$this->assertCount(1, $this->made);
	}

	/**
	 * Not handed out, and replaced by a session made and kept: one Etherpad
	 * no longer has (taken away with the file's trash, say), one for
	 * another group or another author, and one with less to run than a
	 * new one less the hour - an editor is turned away when it runs out.
	 * Exactly that much is still enough.
	 *
	 * @param ?array{groupID:string,authorID:string,validUntil:int} $session
	 */
	#[DataProvider('kept')]
	public function testAKeptSessionIsHandedOutOnlyAsEtherpadConfirmsIt(?array $session, bool $handedOut): void {
		$sessions = $this->sessions($this->client($session));
		$this->open($sessions);

		$this->assertSame($handedOut ? 's.made1' : 's.made2', $this->open($sessions)['sessionId']);
		$this->assertSame($handedOut ? 's.made1' : 's.made2', $this->keptValue());
	}

	/** @return array<string, array{?array{groupID:string,authorID:string,validUntil:int}, bool}> */
	public static function kept(): array {
		return [
			'gone' => [null, false],
			'another group' => [['groupID' => 'g.QRSTUVWXYZABCDEF', 'authorID' => self::AUTHOR, 'validUntil' => self::NEW_UNTIL], false],
			'another author' => [['groupID' => self::GROUP, 'authorID' => 'a.someone', 'validUntil' => self::NEW_UNTIL], false],
			'just too little left' => [['groupID' => self::GROUP, 'authorID' => self::AUTHOR, 'validUntil' => self::NEW_UNTIL - PublicLinkSessions::REUSE_SECONDS - 1], false],
			'just enough left' => [['groupID' => self::GROUP, 'authorID' => self::AUTHOR, 'validUntil' => self::NEW_UNTIL - PublicLinkSessions::REUSE_SECONDS], true],
		];
	}

	/**
	 * A shorter lifetime keeps a session for a third of it, so a visitor
	 * still gets two thirds and never a session that has run out.
	 */
	public function testAShortLifetimeKeepsASessionForAThirdOfIt(): void {
		$until = FixedClock::NOW + 1800;
		$sessions = $this->sessions($this->client(['groupID' => self::GROUP, 'authorID' => self::AUTHOR, 'validUntil' => $until - 601]));
		$this->open($sessions, $until);

		$this->assertSame('s.made2', $this->open($sessions, $until)['sessionId']);
		$this->assertSame(600, $this->held->getArrayCopy()[array_key_first($this->held->getArrayCopy())]['ttl']);
	}

	/**
	 * Etherpad made a session but said nothing usable about the kept one:
	 * said, because the link then makes one an open. An Etherpad that is
	 * away fails the making too, which is reported where it fails.
	 */
	public function testAKeptSessionEtherpadCannotDescribeIsReplacedAndSaid(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getSessionInfo')->willThrowException(new EtherpadClientException('Etherpad did not describe the session.'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$sessions = $this->sessions($client, logger: $logger);
		$this->open($sessions);

		$this->assertSame('s.made2', $this->open($sessions)['sessionId']);
	}

	/**
	 * A check of the kept session that times out fails the open: Etherpad
	 * is away, and making a session would only wait out another timeout.
	 */
	public function testACheckThatTimesOutMakesNoSession(): void {
		$timeout = new EtherpadClientException('Etherpad API request failed: getSessionInfo', 0, new \RuntimeException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received'));
		$client = $this->createMock(EtherpadClient::class);
		$client->method('configuredApiHost')->willReturn('https://pad.example.test');
		$client->method('getSessionInfo')->willThrowException($timeout);
		$sessions = $this->sessions($client);
		$this->open($sessions);

		try {
			$sessions->sessionFor(self::LINK, self::AUTHOR, self::GROUP, self::NEW_UNTIL, static fn (): string => throw new \LogicException('made a session'));
			$this->fail('opened');
		} catch (EtherpadClientException $e) {
			$this->assertSame($timeout, $e);
		}
	}

	/** An HTTP error is no timeout: the open makes a session, and says it could not confirm the kept one. */
	public function testACheckThatFailsFastStillMakesASession(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('configuredApiHost')->willReturn('https://pad.example.test');
		$client->method('getSessionInfo')->willThrowException(new EtherpadClientException('Etherpad API request failed: getSessionInfo', 0, new EtherpadClientException('Etherpad API HTTP error (502)')));
		$sessions = $this->sessions($client);
		$this->open($sessions);

		$this->assertSame('s.made2', $this->open($sessions)['sessionId']);
	}

	public function testAnEtherpadThatIsAwayIsNotReportedTwice(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getSessionInfo')->willThrowException(new EtherpadClientException('cURL error 7'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$sessions = $this->sessions($client, logger: $logger);
		$this->open($sessions);

		$this->expectException(EtherpadClientException::class);
		$sessions->sessionFor(self::LINK, self::AUTHOR, self::GROUP, self::NEW_UNTIL, static fn (): string => throw new EtherpadClientException('cURL error 7'));
	}

	/**
	 * A cache that fails - Redis gone, say - keeps nothing, and the open
	 * goes ahead. Said, since the link then makes a session an open again.
	 */
	public function testACacheThatFailsKeepsNothingAndSaysSo(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willThrowException(new \RuntimeException('Redis server went away'));
		$cache->method('set')->willThrowException(new \RuntimeException('Redis server went away'));
		$logger = $this->createMock(LoggerInterface::class);
		// The read and the write, on each of the two opens.
		$logger->expects($this->exactly(4))->method('warning');
		$sessions = $this->sessions($this->client(null), $this->factoryFor($cache), $logger);

		$this->assertSame(['sessionId' => 's.made1', 'validUntil' => self::NEW_UNTIL], $this->open($sessions));
		$this->assertSame('s.made2', $this->open($sessions)['sessionId']);
	}

	/**
	 * Redis can already fail while the cache is set up: Nextcloud's
	 * connects when it is made. The open goes ahead all the same.
	 */
	#[DataProvider('factoryFailures')]
	public function testACacheThatFailsToBeSetUpKeepsNothing(string $failing): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturnCallback(static fn (): bool => $failing === 'isAvailable' ? throw new \RuntimeException('Redis server went away') : true);
		$factory->method('createDistributed')->willThrowException(new \RuntimeException('Redis server went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$sessions = $this->sessions($this->client(null), $factory, $logger);

		$this->assertSame(['sessionId' => 's.made1', 'validUntil' => self::NEW_UNTIL], $this->open($sessions));
	}

	/** @return array<string, array{string}> */
	public static function factoryFailures(): array {
		return [
			'asking whether there is one' => ['isAvailable'],
			'making it' => ['createDistributed'],
		];
	}

	/** Without a memory cache every open makes a session, as before, and no cache is built. */
	public function testWithoutAMemoryCacheEveryOpenMakesOne(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$factory->expects($this->never())->method('createDistributed');
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('getSessionInfo');
		$sessions = $this->sessions($client, $factory);

		$this->open($sessions);
		$this->open($sessions);

		$this->assertCount(2, $this->made);
	}

	/**
	 * Kept under an HMAC of the Etherpad address, the link and the group:
	 * the key does not carry the token, and does not give away one that
	 * could be guessed without the instance's secret.
	 */
	public function testTheKeyIsAnHmacOfTheEtherpadTheLinkAndTheGroup(): void {
		$host = 'https://pad.example.test';
		$client = $this->createMock(EtherpadClient::class);
		$client->method('configuredApiHost')->willReturnCallback(static function () use (&$host): string {
			return $host;
		});
		$client->method('getSessionInfo')->willReturn(null);
		$hmacs = new \ArrayObject();
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static function (string $message, string $password = '') use ($hmacs): string {
			$hmacs->append([$message, $password]);
			return hash('sha256', 'instance-secret' . $message, true);
		});
		$sessions = new PublicLinkSessions(new PublicLinkCache($this->factoryFor($this->memoryCache()), $crypto), $client, new FixedClock(), $this->createMock(LoggerInterface::class));

		$this->open($sessions);
		$sessions->sessionFor(self::LINK, self::AUTHOR, 'g.QRSTUVWXYZABCDEF', self::NEW_UNTIL, fn (): string => $this->make());
		$host = 'https://other-pad.example.test';
		$this->open($sessions);

		$this->assertCount(3, $this->held);
		foreach (array_keys($this->held->getArrayCopy()) as $key) {
			$this->assertStringNotContainsString('a-share-token', $key);
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
		}
		// The instance's secret: no password of its own.
		$this->assertSame(['https://pad.example.test' . "\n" . self::LINK . "\n" . self::GROUP, ''], $hmacs[0]);
	}

	/** @return array{sessionId:string,validUntil:int} */
	private function open(PublicLinkSessions $sessions, int $validUntil = self::NEW_UNTIL): array {
		return $sessions->sessionFor(self::LINK, self::AUTHOR, self::GROUP, $validUntil, fn (): string => $this->make());
	}

	private function make(): string {
		$this->made->append('s.made' . ($this->made->count() + 1));
		return $this->made[$this->made->count() - 1];
	}

	private function keptValue(): mixed {
		$copy = $this->held->getArrayCopy();
		return $copy[array_key_first($copy)]['value'] ?? null;
	}

	/** @param ?array{groupID:string,authorID:string,validUntil:int} $session */
	private function client(?array $session): EtherpadClient {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('configuredApiHost')->willReturn('https://pad.example.test');
		$client->method('getSessionInfo')->with('s.made1')->willReturn($session);
		return $client;
	}

	private function sessions(EtherpadClient $client, ?ICacheFactory $factory = null, ?LoggerInterface $logger = null): PublicLinkSessions {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash('sha256', $message, true));
		return new PublicLinkSessions(
			new PublicLinkCache($factory ?? $this->factoryFor($this->memoryCache()), $crypto),
			$client,
			new FixedClock(),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	/** A memory cache, keeping what is set with its time to live. */
	private function memoryCache(): ICache {
		$held = $this->held;
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static fn (string $key): mixed => isset($held[$key]) ? $held[$key]['value'] : null);
		$cache->method('set')->willReturnCallback(
			static function (string $key, mixed $value, int $ttl = 0) use ($held): bool {
				$held[$key] = ['value' => $value, 'ttl' => $ttl];
				return true;
			}
		);
		return $cache;
	}

	private function factoryFor(ICache $cache): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		return $factory;
	}
}
