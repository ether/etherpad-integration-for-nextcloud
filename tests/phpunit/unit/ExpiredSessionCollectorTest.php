<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\CollectExpiredSessionsJob;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExpiredSessionCollector;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Etherpad never removes an expired session, and listSessionsOfAuthor
 * walks the author's whole index one awaited lookup at a time. Left alone
 * the list grows with every pad this user has ever opened, until the
 * listing alone can spend the revoke budget — at which point a logout
 * revokes nothing.
 */
class ExpiredSessionCollectorTest extends TestCase {
	private const AUTHOR = 'a.author';

	/** @return array{groupID:string,validUntil:int} */
	private static function expired(): array {
		return ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() - 60];
	}

	/** @return array{groupID:string,validUntil:int} */
	private static function live(): array {
		return ['groupID' => 'g.AAAAAAAAAAAAAAAA', 'validUntil' => time() + 3600];
	}

	/** @return array<string,array{groupID:string,validUntil:int}> */
	private static function expiredSessions(int $count, string $prefix = 's.old'): array {
		$sessions = [];
		for ($i = 0; $i < $count; $i++) {
			$sessions[$prefix . $i] = self::expired();
		}
		return $sessions;
	}

	public function testLeavesANoteOnceTheBacklogIsWorthSweeping(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(false);
		$jobList->expects(self::once())->method('add')->with(
			CollectExpiredSessionsJob::class,
			['uid' => 'alice', 'authorId' => self::AUTHOR],
		);

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, self::expiredSessions(50));
	}

	/**
	 * A day of ordinary use leaves a handful behind. Queueing a sweep for
	 * those would put a row in the job table on nearly every logout.
	 */
	public function testSaysNothingAboutAHandful(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, self::expiredSessions(49));
	}

	/** Live sessions are not a backlog; they are the thing being protected. */
	public function testDoesNotCountLiveSessionsAsBacklog(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');

		$sessions = [];
		for ($i = 0; $i < 80; $i++) {
			$sessions['s.live' . $i] = self::live();
		}

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, $sessions);
	}

	/**
	 * A second note would not be ignored: Nextcloud updates the row and
	 * clears last_run and reserved_at, so a sweep this job deliberately put
	 * a minute out would be released by the next pad open.
	 */
	public function testDoesNotTouchANoteThatIsAlreadyThere(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(true);
		$jobList->expects(self::never())->method('add');

		$this->collector($this->createMock(EtherpadClient::class), $jobList)
			->noteBacklog('alice', self::AUTHOR, self::expiredSessions(80));
	}

	public function testDeletesTheExpiredOnesAndLeavesTheLiveOnesAlone(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.old' => self::expired(),
			's.live' => self::live(),
			's.older' => self::expired(),
		]);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id) use (&$removed): void {
				$removed[] = $id;
			}
		);

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

		self::assertSame(['s.old', 's.older'], $removed);
		self::assertSame(['deleted' => 2, 'remaining' => 0, 'retry' => false], $result);
	}

	/**
	 * The ceiling has to be reported honestly, because the job decides
	 * whether to come back from this number alone.
	 */
	public function testReportsWhatDidNotFitInOneRun(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(400));

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

		self::assertSame(250, $result['deleted']);
		self::assertSame(150, $result['remaining']);
	}

	/**
	 * A session somebody else removed in the meantime is the outcome asked
	 * for, so it counts as handled — otherwise the job would come back for
	 * work that no longer exists.
	 */
	public function testCountsAnAlreadyGoneSessionAsDone(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn([
			's.old' => self::expired(),
			's.gone' => self::expired(),
		]);
		$client->method('deleteSession')->willReturnCallback(
			static function (string $id): void {
				if ($id === 's.gone') {
					throw new EtherpadClientException('sessionID does not exist');
				}
			}
		);

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

		self::assertSame(1, $result['deleted']);
		self::assertSame(0, $result['remaining'], 'a session that is already gone is not left over');
	}

	/**
	 * The ceiling counts what was dealt with, not what this run deleted.
	 * An author whose backlog was already cleared by somebody else would
	 * otherwise never reach it and walk the whole index in one run — the
	 * ceiling would exist only for the happy path.
	 */
	public function testAnAlreadyClearedBacklogStillHitsTheCeiling(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(400));
		$calls = 0;
		$client->method('deleteSession')->willReturnCallback(
			static function () use (&$calls): void {
				$calls++;
				throw new EtherpadClientException('sessionID does not exist');
			}
		);

		$result = $this->collector($client)->collect('alice', self::AUTHOR);

		self::assertSame(250, $calls, 'the run should stop at the ceiling, not walk the whole index');
		self::assertSame(['deleted' => 0, 'remaining' => 150, 'retry' => false], $result);
	}

	/**
	 * A pad server refusing this call refuses the next one too. Stopping
	 * keeps one broken sweep from writing hundreds of warnings, and the
	 * count left over is what makes the job try again later.
	 */
	public function testStopsAtTheFirstRealFailureAndSaysWhatIsLeft(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')->willReturn(self::expiredSessions(10));
		$calls = 0;
		$client->method('deleteSession')->willReturnCallback(
			static function () use (&$calls): void {
				$calls++;
				throw new EtherpadClientException('Connection timed out');
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$result = $this->collector($client, logger: $logger)->collect('alice', self::AUTHOR);

		self::assertSame(1, $calls, 'one failure is enough to know the rest will fail too');
		self::assertSame(['deleted' => 0, 'remaining' => 10, 'retry' => false], $result);
	}

	/** Nothing about collecting may take a pad server outage further. */
	public function testSurvivesAnUnreachablePadServer(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listSessionsOfAuthor')
			->willThrowException(new EtherpadClientException('Connection timed out'));

		self::assertSame(
			['deleted' => 0, 'remaining' => 0, 'retry' => true],
			$this->collector($client)->collect('alice', self::AUTHOR),
			'a failed listing is a retry, not an empty backlog',
		);
	}

	private function collector(
		EtherpadClient $client,
		?IJobList $jobList = null,
		?LoggerInterface $logger = null,
	): ExpiredSessionCollector {
		return new ExpiredSessionCollector(
			$client,
			$jobList ?? $this->createMock(IJobList::class),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
