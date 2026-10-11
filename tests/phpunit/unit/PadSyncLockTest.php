<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadFileLockRetryExhaustedException;
use OCA\EtherpadNextcloud\Service\PadSyncLock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryLockingProvider;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class PadSyncLockTest extends TestCase {
	private const KEY = 'etherpad_nextcloud:sync:42';

	public function testTheSyncRunsHoldingTheFilesLockAndLetsGoAfterIt(): void {
		$locks = new InMemoryLockingProvider();
		$lock = new PadSyncLock($locks, static function (int $delay): void {
		});

		$heldDuring = $lock->hold(42, static fn (): bool => $locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE));

		$this->assertTrue($heldDuring);
		$this->assertFalse($locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE));
	}

	/** A sync that fails lets go too, or every later sync of the file would wait in vain. */
	public function testASyncThatThrowsLetsGo(): void {
		$locks = new InMemoryLockingProvider();
		$lock = new PadSyncLock($locks, static function (int $delay): void {
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

	/** The other sync lets go while this one waits: this one runs after it. */
	public function testASecondSyncWaitsForTheFirst(): void {
		$locks = new InMemoryLockingProvider();
		$locks->acquireLock(self::KEY, ILockingProvider::LOCK_EXCLUSIVE);
		$waits = [];
		$lock = new PadSyncLock($locks, static function (int $delay) use (&$waits, $locks): void {
			$waits[] = $delay;
			if (count($waits) === 2) {
				$locks->releaseLock(self::KEY, ILockingProvider::LOCK_EXCLUSIVE);
			}
		});

		$this->assertSame('ran', $lock->hold(42, static fn (): string => 'ran'));
		$this->assertSame([100000, 200000], $waits);
	}

	public function testASyncTheOtherHoldsOffThroughEveryWaitDoesNotRun(): void {
		$locks = new InMemoryLockingProvider();
		$locks->acquireLock(self::KEY, ILockingProvider::LOCK_EXCLUSIVE);
		$waits = [];
		$lock = new PadSyncLock($locks, static function (int $delay) use (&$waits): void {
			$waits[] = $delay;
		});
		$ran = false;

		try {
			$lock->hold(42, static function () use (&$ran): void {
				$ran = true;
			});
			$this->fail('Nothing thrown.');
		} catch (PadFileLockRetryExhaustedException $e) {
			$this->assertSame(5, $e->getRetryAttempts());
		}

		$this->assertFalse($ran);
		$this->assertSame([100000, 200000, 400000, 800000, 1600000], $waits);
		// The other's lock stays the other's.
		$this->assertTrue($locks->isLocked(self::KEY, ILockingProvider::LOCK_EXCLUSIVE));
	}

	public function testSyncsOfTwoFilesDoNotWaitForEachOther(): void {
		$locks = new InMemoryLockingProvider();
		$lock = new PadSyncLock($locks, function (int $delay): void {
			$this->fail('Waited.');
		});

		$this->assertSame('both', $lock->hold(42, static fn (): string => $lock->hold(43, static fn (): string => 'both')));
	}
}
