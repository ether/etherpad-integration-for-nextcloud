<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGroupSessionsJob;
use OCA\EtherpadNextcloud\Service\Binding;
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
		$bindings->method('findByPadId')->willReturn(new Binding(42, self::GROUP . '$notes', BindingService::ACCESS_PROTECTED, BindingService::STATE_ACTIVE));
		$bindings->method('placesOf')->willReturn([42 => [3, 'files_trashbin/files/Notes.pad.d1']]);
		$bindings->method('isInFiles')->willReturn(false);
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
