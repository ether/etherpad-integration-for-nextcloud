<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\ExternalPadExportNotFoundException;
use OCA\EtherpadNextcloud\Service\EtherpadFailureLog;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EtherpadFailureLogTest extends TestCase {
	/** @var list<array{string, string, array<string,mixed>}> */
	private array $logged = [];

	/**
	 * An outage reaches every open viewer's sync: one warning a minute for
	 * the instance, claimed in the distributed cache, and debug for the
	 * rest of that minute.
	 */
	public function testOneWarningAMinuteAndTheRestAtDebug(): void {
		$claims = [true, false];
		$cache = $this->createMock(IMemcache::class);
		$cache->expects($this->exactly(2))->method('add')->with('warned', '1', 60)->willReturnCallback(static function () use (&$claims): bool {
			return array_shift($claims);
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->with('etherpad_nextcloud/etherpad-failed/')->willReturn($cache);

		$log = new EtherpadFailureLog($factory, $this->logger());
		$log->report('Etherpad failed while answering a pad request.', new EtherpadClientException('Etherpad API request failed: getText'), ['fileId' => 42]);
		$log->report('Etherpad failed while answering a pad request.', new EtherpadClientException('Etherpad API request failed: getText'), ['fileId' => 43]);

		$this->assertSame([['warning', 42], ['debug', 43]], array_map(static fn (array $line): array => [$line[0], $line[2]['fileId']], $this->logged));
		$this->assertSame(['app' => 'etherpad_nextcloud', 'fileId' => 42, 'error' => EtherpadClientException::class], array_intersect_key($this->logged[0][2], ['app' => 1, 'fileId' => 1, 'error' => 1]));
	}

	/** Nothing to share the minute with - no cache, or one without add() - and each failure is a warning. */
	public function testWithoutACacheToShareEachFailureIsAWarning(): void {
		$none = $this->createMock(ICacheFactory::class);
		$none->method('isAvailable')->willReturn(false);
		$none->expects($this->never())->method('createDistributed');
		$plain = $this->createMock(ICacheFactory::class);
		$plain->method('isAvailable')->willReturn(true);
		$plain->method('createDistributed')->willReturn($this->createMock(ICache::class));

		foreach ([$none, $plain] as $factory) {
			$log = new EtherpadFailureLog($factory, $this->logger());
			$log->report('m', new EtherpadClientException('down'));
			$log->report('m', new EtherpadClientException('down'));
		}

		$this->assertSame(['warning', 'warning', 'warning', 'warning'], array_column($this->logged, 0));
	}

	/** Only this instance's Etherpad failing: not a pad too large to show, not a pad on another server. */
	public function testWhatCountsAsThisInstancesEtherpadFailing(): void {
		$this->assertTrue(EtherpadFailureLog::isOwnEtherpadFailing(new EtherpadClientException('Etherpad API request failed: createPad')));
		foreach ([
			new EtherpadTooLargeException('Pad export is larger than 5242880 bytes.'),
			new ExternalPadException('Public export HTTP error (500)'),
			new ExternalPadExportNotFoundException('Public export returned 404.'),
			new \RuntimeException('down'),
		] as $e) {
			$this->assertFalse(EtherpadFailureLog::isOwnEtherpadFailing($e), $e::class);
		}
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context) use ($level): void {
				$this->logged[] = [$level, $message, $context];
			});
		}
		return $logger;
	}
}
