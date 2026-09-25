<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\ExternalPadExportNotFoundException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Service\ApiErrorLog;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiErrorLogTest extends TestCase {
	private const UNREACHABLE = 'Etherpad could not be reached while answering a request.';
	private const REFUSED = 'Etherpad refused a request.';

	/** @var list<array{string, string, array<string,mixed>}> */
	private array $logged = [];

	/**
	 * An outage reaches every open viewer's sync: one warning a minute for
	 * the instance, claimed in the distributed cache, and debug for the
	 * rest of that minute - each kind on its own, so a refusal is not
	 * swallowed by an outage in the same minute.
	 */
	public function testOneWarningAMinuteForEachKindAndTheRestAtDebug(): void {
		$claimed = [];
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturnCallback(static function (string $key, mixed $value, int $ttl) use (&$claimed): bool {
			if ($ttl !== 60 || isset($claimed[$key])) {
				return false;
			}
			return $claimed[$key] = true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->with('etherpad_nextcloud/api-errors/')->willReturn($cache);

		$log = new ApiErrorLog($factory, $this->logger());
		$log->report(new EtherpadClientException('Etherpad API request failed: getText'), ['fileId' => 42]);
		$log->report(new EtherpadClientException('Etherpad API request failed: getText'), ['fileId' => 43]);
		$log->report(new EtherpadRefusedException('Etherpad API error (getText): padID does not exist'), ['fileId' => 44]);

		$this->assertSame(
			[['warning', self::UNREACHABLE, 42], ['debug', self::UNREACHABLE, 43], ['warning', self::REFUSED, 44]],
			array_map(static fn (array $line): array => [$line[0], $line[1], $line[2]['fileId']], $this->logged),
		);
		$this->assertSame(['app' => 'etherpad_nextcloud', 'fileId' => 42, 'error' => EtherpadClientException::class], array_intersect_key($this->logged[0][2], ['app' => 1, 'fileId' => 1, 'error' => 1]));
	}

	/**
	 * Nothing to share the minute with - no cache, one without add(), or
	 * one that fails - and each failure is a warning. A cache that throws
	 * must not fail the answer the line reports for.
	 */
	public function testWithoutACacheToShareEachFailureIsAWarning(): void {
		$none = $this->createMock(ICacheFactory::class);
		$none->method('isAvailable')->willReturn(false);
		$none->expects($this->never())->method('createDistributed');
		$plain = $this->createMock(ICacheFactory::class);
		$plain->method('isAvailable')->willReturn(true);
		$plain->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$failing = $this->createMock(IMemcache::class);
		$failing->method('add')->willThrowException(new \RuntimeException('Redis server went away'));
		$broken = $this->createMock(ICacheFactory::class);
		$broken->method('isAvailable')->willReturn(true);
		$broken->method('createDistributed')->willReturn($failing);

		foreach ([$none, $plain, $broken] as $factory) {
			$log = new ApiErrorLog($factory, $this->logger());
			$log->report(new EtherpadClientException('down'));
			$log->report(new EtherpadClientException('down'));
		}

		$this->assertSame(array_fill(0, 6, 'warning'), array_column($this->logged, 0));
	}

	/**
	 * The unforeseen is an error under the line the mapper gives; a .pad and
	 * its row that do not match are a warning; anything else is what the
	 * request itself got wrong, a debug line with its reason.
	 */
	public function testEachOtherErrorAtTheLevelItDeserves(): void {
		$log = new ApiErrorLog($this->createMock(ICacheFactory::class), $this->logger());
		$log->report(new \RuntimeException('Detailed failure.'), [], 'Pad restore API failed');
		$log->report(new BindingException('Binding pad ID mismatch.'));
		$log->report(new MissingBindingException('No binding exists for this file.'));
		$log->report(new WaitingBindingException('Pad binding is not active.'));
		$log->report(new ExternalPadException('Public export HTTP error (500)'));

		$this->assertSame([
			['error', 'Pad restore API failed', 'Detailed failure.'],
			['warning', 'A .pad file and its pad binding could not be matched.', 'Binding pad ID mismatch.'],
			['debug', 'A request was refused.', 'No binding exists for this file.'],
			['debug', 'A request was refused.', 'Pad binding is not active.'],
			['debug', 'A request was refused.', 'Public export HTTP error (500)'],
		], array_map(static fn (array $line): array => [$line[0], $line[1], $line[2]['error_message']], $this->logged));
	}

	/** Not reachable is this instance's Etherpad only: not a refusal, not a pad too large, not a pad on another server. */
	public function testWhatCountsAsEtherpadNotReachable(): void {
		$this->assertTrue(ApiErrorLog::isEtherpadUnreachable(new EtherpadClientException('Etherpad API request failed: createPad')));
		foreach ([
			new EtherpadRefusedException('Etherpad API error (createPad): padID does already exist'),
			new EtherpadTooLargeException('Pad export is larger than 5242880 bytes.'),
			new ExternalPadException('Public export HTTP error (500)'),
			new ExternalPadExportNotFoundException('Public export returned 404.'),
			new \RuntimeException('down'),
		] as $e) {
			$this->assertFalse(ApiErrorLog::isEtherpadUnreachable($e), $e::class);
		}
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context) use ($level): void {
				$this->logged[] = [$level, $message, $context];
			});
		}
		return $logger;
	}
}
