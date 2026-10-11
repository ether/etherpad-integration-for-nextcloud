<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadFileLockRetryExhaustedException;
use OCA\EtherpadNextcloud\Exception\PadLostException;
use OCA\EtherpadNextcloud\Service\PadSyncLock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryLockingProvider;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadSyncLockTest extends TestCase {
	private const KEY = 'etherpad_nextcloud:sync:42';

	public function testTheSyncRunsHoldingTheFilesLockAndLetsGoAfterIt(): void {
		$locks = new InMemoryLockingProvider();
		$lock = $this->lock($locks, static function (int $delay): void {
		});

		$heldDuring = $lock->hold(42, static fn (): bool => $locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE));

		$this->assertTrue($heldDuring);
		$this->assertFalse($locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE));
	}

	/** A sync that fails lets go too, or every later sync of the file would wait in vain. */
	public function testASyncThatThrowsLetsGo(): void {
		$locks = new InMemoryLockingProvider();
		$lock = $this->lock($locks, static function (int $delay): void {
		});

		try {
			$lock->hold(42, static function (): void {
				throw new \RuntimeException('Etherpad refused.');
			});
			$this->fail('Nothing thrown.');
		} catch (\RuntimeException $e) {
			$this->assertSame('Etherpad refused.', $e->getMessage());
		}

		$this->assertFalse($locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE));
	}

	/** The other sync lets go while this one waits: this one runs after it, asked again about every 100 ms. */
	public function testASecondSyncWaitsForTheFirst(): void {
		$locks = new InMemoryLockingProvider();
		$locks->acquireLock(self::KEY, ILockingProvider::LOCK_EXCLUSIVE);
		$waits = [];
		$lock = $this->lock($locks, static function (int $delay) use (&$waits, $locks): void {
			$waits[] = $delay;
			if (count($waits) === 2) {
				$locks->releaseLock(self::KEY, ILockingProvider::LOCK_EXCLUSIVE);
			}
		});

		$this->assertSame('ran', $lock->hold(42, static fn (): string => 'ran'));
		$this->assertCount(2, $waits);
		foreach ($waits as $delay) {
			$this->assertGreaterThanOrEqual(75000, $delay);
			$this->assertLessThanOrEqual(125000, $delay);
		}
	}

	public function testASyncTheOtherHoldsOffThroughEveryWaitDoesNotRun(): void {
		$locks = new InMemoryLockingProvider();
		$locks->acquireLock(self::KEY, ILockingProvider::LOCK_EXCLUSIVE);
		$waits = [];
		$lock = $this->lock($locks, static function (int $delay) use (&$waits): void {
			$waits[] = $delay;
		});
		$ran = false;

		try {
			$lock->hold(42, static function () use (&$ran): void {
				$ran = true;
			});
			$this->fail('Nothing thrown.');
		} catch (PadFileLockRetryExhaustedException $e) {
			$this->assertSame(count($waits), $e->getRetryAttempts());
		}

		$this->assertFalse($ran);
		// About three seconds of waiting, never more.
		$this->assertLessThanOrEqual(3000000, array_sum($waits));
		$this->assertGreaterThan(3000000 - 125000, array_sum($waits));
		// The other's lock stays the other's.
		$this->assertTrue($locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE));
	}

	public function testSyncsOfTwoFilesDoNotWaitForEachOther(): void {
		$locks = new InMemoryLockingProvider();
		$lock = $this->lock($locks, function (int $delay): void {
			$this->fail('Waited.');
		});

		$this->assertSame('both', $lock->hold(42, static fn (): string => $lock->hold(43, static fn (): string => 'both')));
	}

	/**
	 * A lock that cannot be let go - the database gone, say - is logged: it
	 * neither hides what the sync threw, which the API answers with its own
	 * code, nor fails a sync that went through.
	 */
	public function testALockThatCannotBeLetGoHidesNothingTheSyncDid(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('releaseLock')->willThrowException(new \RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with('The sync lock of a .pad file could not be let go');
		$lock = new PadSyncLock($locks, $logger, static function (int $delay): void {
		});

		$this->assertSame('written', $lock->hold(42, static fn (): string => 'written'));
		$this->expectException(PadLostException::class);
		$lock->hold(42, static function (): void {
			throw new PadLostException('Etherpad has lost the pad of this file.');
		});
	}

	/** @param \Closure(int): void $sleeper */
	private function lock(ILockingProvider $locks, \Closure $sleeper): PadSyncLock {
		return new PadSyncLock($locks, $this->createMock(LoggerInterface::class), $sleeper);
	}
}
