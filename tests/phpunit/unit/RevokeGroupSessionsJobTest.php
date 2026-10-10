<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGroupSessionsJob;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\GroupSessionRevoker;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\SessionDeletes;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The passes are SessionSweepJob's, which CollectExpiredSessionsJobTest
 * holds to its rules; this holds the group's job to its argument, its
 * pass and what it says of the rest.
 */
class RevokeGroupSessionsJobTest extends TestCase {
	private const GROUP = 'g.AAAAAAAAAAAAAAAA';

	public function testComesBackForWhatThePassDidNotReach(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->expects(self::once())->method('revokeRest')->with(self::GROUP)
			->willReturn(['deleted' => 250, 'remaining' => 50, 'retry' => false, 'nextDueAt' => null]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(RevokeGroupSessionsJob::class, 1_000_060, ['groupId' => self::GROUP]);

		$this->start($revoker, $jobList, ['groupId' => self::GROUP]);
	}

	/**
	 * A pass that leaves nothing looks once more, ten minutes later: an open
	 * under way at the delete may make its session after the first pass.
	 */
	public function testLooksOnceMoreAfterItLeftNothing(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->method('revokeRest')->willReturn(['deleted' => 3, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(RevokeGroupSessionsJob::class, 1_000_600, ['groupId' => self::GROUP, 'again' => 1]);

		$this->start($revoker, $jobList, ['groupId' => self::GROUP]);
	}

	/** A pass that ends the job - a file back, a pad of no file, the group gone - takes no second look. */
	public function testTakesNoSecondLookWhenThePassEndedTheJob(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->method('revokeRest')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => true]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('scheduleAfter');

		$this->start($revoker, $jobList, ['groupId' => self::GROUP]);
	}

	public function testEndsAfterTheSecondLookLeftNothing(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->method('revokeRest')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('scheduleAfter');

		$this->start($revoker, $jobList, ['groupId' => self::GROUP, 'again' => 1]);
	}

	/**
	 * An open that passed its check before the delete and made its session
	 * after the first pass - Etherpad slow, a retry - is taken by the second
	 * look.
	 */
	public function testTheSecondLookTakesASessionASlowOpenMadeAfterTheFirst(): void {
		$sessions = [];
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturn([self::GROUP . '$notes']);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function () use (&$sessions): array {
			return $sessions;
		});
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			$removed[] = $id;
		});
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('filesOfPads')->willReturn([42]);
		$bindings->method('placesOf')->willReturn([42 => [3, 'files_trashbin/files/Notes.pad.d1']]);
		$bindings->method('anyInFiles')->willReturn(false);
		$clock = new FixedClock(1_000_000);
		$logger = $this->createMock(LoggerInterface::class);
		$revoker = new GroupSessionRevoker($client, $bindings, new SessionDeletes($client, new ManagedPadLifecycle($client, $logger), $logger), $this->createMock(IJobList::class), $logger, $clock);
		$queued = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willReturnCallback(static function (string $job, int $at, array $argument) use (&$queued): void {
			$queued[] = [$at, $argument];
		});

		// The first pass finds none: the open is still waiting on Etherpad.
		$this->start($revoker, $jobList, ['groupId' => self::GROUP], $logger, $clock);
		// Then it makes its session.
		$sessions = ['s.late' => ['groupID' => self::GROUP, 'validUntil' => 1_000_000 + 21600]];
		self::assertSame([[1_000_600, ['groupId' => self::GROUP, 'again' => 1]]], $queued);
		$clock->advance(600);
		$this->start($revoker, $jobList, $queued[0][1], $logger, $clock);

		self::assertSame(['s.late'], $removed);
	}

	public function testBacksOffWhenEtherpadRefuses(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->method('revokeRest')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())->method('scheduleAfter')->with(RevokeGroupSessionsJob::class, 1_000_060, ['groupId' => self::GROUP, 'attempt' => 1]);

		$this->start($revoker, $jobList, ['groupId' => self::GROUP]);
	}

	/** Its row is gone before it runs: a pass it cannot queue says what becomes of the rest. */
	public function testSaysWhatBecomesOfTheRestWhenItCannotQueueTheNextPass(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->method('revokeRest')->willReturn(['deleted' => 250, 'remaining' => 1, 'retry' => false, 'nextDueAt' => null]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willThrowException(new \RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Could not queue the next revocation of a group\'s Etherpad sessions; the rest expires on its own.',
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP),
		);

		$this->start($revoker, $jobList, ['groupId' => self::GROUP], $logger);
	}

	/**
	 * The passes a second look needs carry `again`: one with more to do, or
	 * refused, comes back as part of it, and the last of them that leaves
	 * nothing looks no third time.
	 */
	public function testTheSecondLookCarriesItsMarkThroughThePassesItNeeds(): void {
		$again = ['groupId' => self::GROUP, 'again' => 1];
		$cases = [
			'more to do' => [$again, ['deleted' => 250, 'remaining' => 1, 'retry' => false, 'nextDueAt' => null], [[1_000_060, $again]]],
			'refused' => [$again, ['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null], [[1_000_060, $again + ['attempt' => 1]]]],
			'its retry leaves nothing' => [$again + ['attempt' => 1], ['deleted' => 2, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null], []],
		];
		foreach ($cases as $case => [$argument, $result, $expected]) {
			$revoker = $this->createMock(GroupSessionRevoker::class);
			$revoker->method('revokeRest')->willReturn($result);
			$queued = [];
			$jobList = $this->createMock(IJobList::class);
			$jobList->method('scheduleAfter')->willReturnCallback(static function (string $job, int $at, array $argument) use (&$queued): void {
				$queued[] = [$at, $argument];
			});

			$this->start($revoker, $jobList, $argument);

			self::assertSame($expected, $queued, $case);
		}
	}

	/** A second look queued beside a waiting retry stands down, as a plain row does. */
	public function testASecondLookStandsDownWhileARetryIsWaiting(): void {
		foreach ([['groupId' => self::GROUP, 'attempt' => 2], ['groupId' => self::GROUP, 'again' => 1, 'attempt' => 1]] as $waiting) {
			$revoker = $this->createMock(GroupSessionRevoker::class);
			$revoker->expects(self::never())->method('revokeRest');
			$jobList = $this->createMock(IJobList::class);
			$jobList->method('has')->willReturnCallback(static fn (string $job, mixed $argument): bool => $argument === $waiting);

			$this->start($revoker, $jobList, ['groupId' => self::GROUP, 'again' => 1]);
		}
	}

	/**
	 * A delete's first pass does not stand down behind a second look's
	 * retry, which looks no more: it runs, and leaving nothing, queues the
	 * delete's own second look. Behind a first pass's retry it stands down,
	 * which looks again itself.
	 */
	public function testADeletesFirstPassKeepsItsSecondLookBesideAnOldOnesRetry(): void {
		$cases = [
			'a second look\'s retry' => [['groupId' => self::GROUP, 'again' => 1, 'attempt' => 1], true],
			'a first pass\'s retry' => [['groupId' => self::GROUP, 'attempt' => 1], false],
		];
		foreach ($cases as $case => [$waiting, $runs]) {
			$revoker = $this->createMock(GroupSessionRevoker::class);
			$revoker->expects($runs ? self::once() : self::never())->method('revokeRest')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null]);
			$queued = [];
			$jobList = $this->createMock(IJobList::class);
			$jobList->method('has')->willReturnCallback(static fn (string $job, mixed $argument): bool => $argument === $waiting);
			$jobList->method('scheduleAfter')->willReturnCallback(static function (string $job, int $at, array $argument) use (&$queued): void {
				$queued[] = [$at, $argument];
			});

			$this->start($revoker, $jobList, ['groupId' => self::GROUP]);

			self::assertSame($runs ? [[1_000_600, ['groupId' => self::GROUP, 'again' => 1]]] : [], $queued, $case);
		}
	}

	/** A second look waiting is no retry: a delete's plain row runs beside it. */
	public function testAPlainRowRunsBesideAWaitingSecondLook(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->expects(self::once())->method('revokeRest')->willReturn(['deleted' => 1, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturnCallback(static fn (string $job, mixed $argument): bool => $argument === ['groupId' => self::GROUP, 'again' => 1]);

		$this->start($revoker, $jobList, ['groupId' => self::GROUP]);
	}

	/** Whoever asks whether a group's sweep waits hears of a second look too. */
	public function testKnowsASecondLookIsQueued(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturnCallback(static fn (string $job, mixed $argument): bool => $argument === ['groupId' => self::GROUP, 'again' => 1, 'attempt' => 2]);

		self::assertTrue(RevokeGroupSessionsJob::isQueued($jobList, ['groupId' => self::GROUP]));
	}

	/**
	 * A pass that ends the job takes the passes it queued itself with it - a
	 * retry, a second look - each of which would only end it again; not the
	 * plain row, which a delete may have queued since.
	 */
	public function testAnEndedJobTakesItsOwnWaitingPassesWithIt(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->method('revokeRest')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => true]);
		$removed = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(static function (mixed $job, mixed $argument = null) use (&$removed): void {
			// The row it runs from goes as it starts; that one is the job itself.
			if (is_string($job)) {
				$removed[] = [$job, $argument];
			}
		});

		$this->start($revoker, $jobList, ['groupId' => self::GROUP]);

		$again = ['groupId' => self::GROUP, 'again' => 1];
		self::assertSame(array_map(static fn (array $argument): array => [RevokeGroupSessionsJob::class, $argument], [
			['groupId' => self::GROUP, 'attempt' => 1],
			['groupId' => self::GROUP, 'attempt' => 2],
			['groupId' => self::GROUP, 'attempt' => 3],
			$again,
			$again + ['attempt' => 1],
			$again + ['attempt' => 2],
			$again + ['attempt' => 3],
			['groupId' => self::GROUP, 'parked' => 1],
		]), $removed);
	}

	/** Giving up after its retries is said, with what becomes of the rest. */
	public function testSaysSoWhenItGivesUp(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->method('revokeRest')->willReturn(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('scheduleAfter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Gave up revoking a group\'s Etherpad sessions after three retries without progress; the rest expires on its own.',
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP && $context['attempts'] === 3),
		);

		$this->start($revoker, $jobList, ['groupId' => self::GROUP, 'attempt' => 3], $logger);
	}

	/**
	 * Its row is gone when it asks whether a retry waits: a failure to ask
	 * lets the pass run, and is said, rather than lose the pass.
	 */
	public function testRunsThePassWhenItCannotTellWhetherARetryWaits(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->expects(self::once())->method('revokeRest')->willReturn(['deleted' => 1, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willThrowException(new \RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Could not tell whether a retry of an Etherpad session sweep is waiting; this pass runs.',
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP),
		);

		$this->start($revoker, $jobList, ['groupId' => self::GROUP], $logger);
	}

	/** An author's row is the collector's, not a group to revoke. */
	public function testIgnoresAnArgumentWithoutAGroup(): void {
		$revoker = $this->createMock(GroupSessionRevoker::class);
		$revoker->expects(self::never())->method('revokeRest');

		$this->start($revoker, $this->createMock(IJobList::class), ['authorId' => 'a.author']);
	}

	/** @param array<string,string|int> $argument */
	private function start(GroupSessionRevoker $revoker, IJobList $jobList, array $argument, ?LoggerInterface $logger = null, ?FixedClock $clock = null): void {
		$job = new RevokeGroupSessionsJob(
			$clock ?? new FixedClock(1_000_000),
			$revoker,
			$jobList,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
		$job->setArgument($argument);
		$job->start($jobList);
	}
}
