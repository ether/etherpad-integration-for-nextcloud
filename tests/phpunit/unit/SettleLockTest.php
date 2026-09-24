<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\SettleLock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryLockingProvider;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettleLockTest extends TestCase {
	private const KEY = 'etherpad_nextcloud:settle:7';

	/** The row is held while it is settled, and let go after, also when settling fails. */
	public function testARowIsHeldWhileItIsSettled(): void {
		$locks = new InMemoryLockingProvider();
		$lock = new SettleLock($locks, $this->createMock(LoggerInterface::class));

		$result = $lock->holding(7, fn (): string => $locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE) ? 'held' : 'not held', static fn (): string => 'busy');

		$this->assertSame('held', $result);
		$this->assertFalse($locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE));

		try {
			$lock->holding(7, static function (): never {
				throw new \RuntimeException('the database went away');
			}, static fn (): string => 'busy');
			$this->fail('The failure goes to the caller.');
		} catch (\RuntimeException $e) {
			$this->assertSame('the database went away', $e->getMessage());
		}
		$this->assertFalse($locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE));
	}

	/**
	 * A row another holds is left to the holder: nothing runs, and the
	 * holder keeps its lock. Taken exclusively, or the second would get in
	 * beside the first.
	 */
	public function testARowHeldByAnotherIsLeftToIt(): void {
		$locks = new InMemoryLockingProvider();
		$logger = $this->createMock(LoggerInterface::class);
		$first = new SettleLock($locks, $logger);
		$second = new SettleLock($locks, $logger);

		$result = $first->holding(
			7,
			function () use ($second, $locks): string {
				$answer = $second->holding(7, fn (): string => $this->fail('Settled a row another holds.'), static fn (): string => 'busy');
				return $answer . ($locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE) ? ', still held' : ', let go');
			},
			static fn (): string => 'busy',
		);

		$this->assertSame('busy, still held', $result);
	}

	/** A lock that cannot be taken for another reason is the caller's to place, and nothing runs. */
	public function testALockThatCannotBeTakenGoesToTheCaller(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new \RuntimeException('the database went away'));

		$this->expectExceptionMessage('the database went away');

		(new SettleLock($locks, $this->createMock(LoggerInterface::class)))
			->holding(7, fn (): string => $this->fail('Settled without the lock.'), fn (): string => $this->fail('Took a failure for another holder.'));
	}

	/** A lock that cannot be let go is logged with its file, and what was settled stands. */
	public function testALockThatCannotBeLetGoIsLogged(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('releaseLock')->willThrowException(new \RuntimeException('the database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Could not release the lock on a pad binding. It waits until the lock expires.',
			$this->callback(static fn (array $context): bool => $context['fileId'] === 7 && $context['error_message'] === 'the database went away'),
		);

		$this->assertSame('settled', (new SettleLock($locks, $logger))->holding(7, static fn (): string => 'settled', static fn (): string => 'busy'));
	}
}
