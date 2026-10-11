<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGroupSessionsJob;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\GroupSessionRevoker;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\SessionDeletes;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What a delete could not take in its request, a background job takes:
 * the group's live sessions, the newest first, while its pads stay away
 * from Files (BindingServiceTest holds where a file counts as in Files).
 */
class GroupSessionRevokerTest extends TestCase {
	private const GROUP = 'g.AAAAAAAAAAAAAAAA';
	private const PAD = 'g.AAAAAAAAAAAAAAAA$notes';
	private const TRASHED = [42 => [3, 'files_trashbin/files/Notes.pad.d1791400000']];
	private const RESTORED = [42 => [3, 'files/Notes.pad']];

	/**
	 * One row, a minute out - an open under way at the delete has made its
	 * session by then - with the job list's own lookup inside it: nothing
	 * asked beside.
	 */
	public function testQueuesAPassForTheGroupAMinuteOut(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('has');
		$jobList->expects(self::once())->method('scheduleAfter')->with(RevokeGroupSessionsJob::class, FixedClock::NOW + 60, ['groupId' => self::GROUP]);

		$this->revoker(jobList: $jobList)->queue(self::GROUP);
	}

	public function testAJobListThatFailsFailsNoDelete(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willThrowException(new \RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Could not queue the revocation of a group\'s remaining Etherpad sessions; they will expire on their own.',
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP),
		);

		self::assertFalse($this->revoker(jobList: $jobList, logger: $logger)->queue(self::GROUP));
	}

	/**
	 * The live ones go, the latest to expire first; the expired are the
	 * collector's, and one only our clock calls expired is live to Etherpad.
	 */
	public function testTakesTheLiveSessionsTheLatestToExpireFirst(): void {
		$client = $this->client([
			's.old' => FixedClock::NOW + 60,
			's.expired' => FixedClock::NOW - 3600,
			's.new' => FixedClock::NOW + 7200,
			's.skewed' => FixedClock::NOW - 60,
		]);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			$removed[] = $id;
		});

		$result = $this->revoker($client, $this->bindings(self::TRASHED))->revokeRest(self::GROUP);

		self::assertSame(['s.new', 's.old', 's.skewed'], $removed);
		self::assertSame(['deleted' => 3, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => false], $result);
	}

	/**
	 * Every file of the group is asked about - one in a row owed a delete
	 * too (BindingService::filesOfPads()) - before the listing and once
	 * more before the first delete; one the file cache does not have is
	 * away without asking.
	 *
	 * @return iterable<string,array{array<int,array{int,string}>,list<array{int,string}>}>
	 */
	public static function away(): iterable {
		yield 'in a trash' => [self::TRASHED, [[3, 'files_trashbin/files/Notes.pad.d1791400000'], [3, 'files_trashbin/files/Notes.pad.d1791400000']]];
		yield 'out of the file cache' => [[], []];
	}

	/**
	 * @param array<int,array{int,string}> $places
	 * @param list<array{int,string}> $asked
	 */
	#[DataProvider('away')]
	public function testTakesThemWhileEveryFileIsAway(array $places, array $asked): void {
		$client = $this->client(['s.live' => FixedClock::NOW + 3600]);
		$client->expects(self::once())->method('deleteSession')->with('s.live');
		$seen = [];
		$bindings = $this->bindings($places, inFiles: static function (int $storage, string $path) use (&$seen): bool {
			$seen[] = [$storage, $path];
			return false;
		});

		$this->revoker($client, $bindings)->revokeRest(self::GROUP);

		self::assertSame($asked, $seen);
	}

	/**
	 * A file in Files has its pad back: whoever opens it gets a session
	 * that is theirs to keep, and the earlier ones expire on their own. So
	 * does a group with a pad of no file, which was never the delete's.
	 *
	 * @return iterable<string,array{string,string}>
	 */
	public static function padsBack(): iterable {
		yield 'a file in Files' => ['back', GroupSessionRevoker::KEPT_FOR_A_FILE_IN_FILES];
		yield 'a pad of no file' => ['none', GroupSessionRevoker::KEPT_FOR_A_PAD_OF_NO_FILE];
	}

	#[DataProvider('padsBack')]
	public function testLeavesThemWhenAPadIsBack(string $case, string $line): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturn(['g.AAAAAAAAAAAAAAAA$away', self::PAD]);
		$client->expects(self::never())->method('listSessionsOfGroup');
		$client->expects(self::never())->method('deleteSession');
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('filesOfPads')->with(['g.AAAAAAAAAAAAAAAA$away', self::PAD])->willReturn($case === 'none' ? null : [41, 42]);
		$bindings->method('placesOf')->willReturn([41 => [3, 'files_trashbin/files/Away.pad.d1'], 42 => [3, 'files/Notes.pad']]);
		$bindings->method('anyInFiles')->willReturnCallback(static fn (array $places): bool => in_array([3, 'files/Notes.pad'], $places, true));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with($line, self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP));

		$result = $this->revoker($client, $bindings, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => true], $result);
	}

	/**
	 * Asked again before every delete: a file restored during the pass, and
	 * opened, keeps the session its opener just got, which expires last and
	 * would otherwise go first.
	 */
	public function testStopsAtAFileRestoredDuringThePass(): void {
		$client = $this->client(['s.1' => FixedClock::NOW + 1, 's.2' => FixedClock::NOW + 2, 's.3' => FixedClock::NOW + 3]);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			$removed[] = $id;
		});
		$asked = 0;
		// In the trash when the pass starts and before its first delete,
		// restored before its second.
		$bindings = $this->bindings(static function () use (&$asked): array {
			return ++$asked <= 2 ? self::TRASHED : self::RESTORED;
		}, inFiles: static fn (int $storage, string $path): bool => $path === 'files/Notes.pad');
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(static function (string $message) use (&$lines): void {
			$lines[] = $message;
		});

		$result = $this->revoker($client, $bindings, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['s.3'], $removed);
		self::assertSame(['deleted' => 1, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => true], $result);
		self::assertSame(['Revoked remaining Etherpad sessions of a group.', GroupSessionRevoker::KEPT_FOR_A_FILE_IN_FILES], $lines);
	}

	/**
	 * The listing takes time too: a file restored while Etherpad lists the
	 * sessions, and opened, has its opener's session among them, first in
	 * line. The look before the first delete keeps it.
	 */
	public function testStopsAtAFileRestoredWhileTheSessionsAreListed(): void {
		$asked = 0;
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturn([self::PAD]);
		$client->method('listSessionsOfGroup')->willReturnCallback(static function () use (&$asked): array {
			// Restored and opened during the listing.
			$asked++;
			return [
				's.before' => ['groupID' => self::GROUP, 'validUntil' => FixedClock::NOW + 60],
				's.opener' => ['groupID' => self::GROUP, 'validUntil' => FixedClock::NOW + 21600],
			];
		});
		$client->expects(self::never())->method('deleteSession');
		$bindings = $this->bindings(static function () use (&$asked): array {
			return $asked === 0 ? self::TRASHED : self::RESTORED;
		}, inFiles: static fn (int $storage, string $path): bool => $path === 'files/Notes.pad');

		$result = $this->revoker($client, $bindings)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => true], $result);
	}

	/**
	 * Asking again is one look at where the files are; whether a user sees
	 * one in Files is asked before the listing, before the first delete,
	 * and after a file moved, once for each move - here within its trash.
	 */
	public function testAsksAboutTheMountsBeforeTheFirstDeleteAndForAMoveOnly(): void {
		$client = $this->client(['s.1' => FixedClock::NOW + 1, 's.2' => FixedClock::NOW + 2, 's.3' => FixedClock::NOW + 3, 's.4' => FixedClock::NOW + 4]);
		$client->expects(self::exactly(4))->method('deleteSession');
		$looks = 0;
		$asks = 0;
		$bindings = $this->bindings(static function () use (&$looks): array {
			// Moved before the second delete.
			return ++$looks <= 2 ? self::TRASHED : [42 => [3, 'files_trashbin/files/Notes.pad.d1791400099']];
		}, inFiles: static function () use (&$asks): bool {
			$asks++;
			return false;
		});

		$this->revoker($client, $bindings)->revokeRest(self::GROUP);

		self::assertSame(5, $looks, 'once before the listing, then before each delete');
		self::assertSame(3, $asks, 'before the listing, before the first delete, and for the move');
	}

	/**
	 * A mount made before a session is listed shows the file without
	 * moving it - a team folder joined, and the pad opened: the mounts are
	 * asked once more before the first delete, and its session stays.
	 */
	public function testAsksAboutTheMountsBeforeTheFirstDeleteThoughNothingMoved(): void {
		$client = $this->client(['s.joined' => FixedClock::NOW + 3600]);
		$client->expects(self::never())->method('deleteSession');
		$asks = 0;
		$bindings = $this->bindings(self::TRASHED, inFiles: static function () use (&$asks): bool {
			// Away when the pass begins; through the new mount once listed.
			return ++$asks > 1;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with(GroupSessionRevoker::KEPT_FOR_A_FILE_IN_FILES, self::anything());

		$result = $this->revoker($client, $bindings, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => true], $result);
	}

	/** A lookup that fails during the pass ends it, says so, and is asked again. */
	public function testALookupThatFailsDuringThePassIsTriedAgain(): void {
		$client = $this->client(['s.1' => FixedClock::NOW + 1, 's.2' => FixedClock::NOW + 2]);
		$client->expects(self::once())->method('deleteSession');
		$asked = 0;
		// Read before the listing and before the first delete; failing before the second.
		$bindings = $this->bindings(static function () use (&$asked): array {
			if (++$asked > 2) {
				throw new \RuntimeException('database gone');
			}
			return self::TRASHED;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			GroupSessionRevoker::FILES_NOT_LOOKED_UP,
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP && str_contains((string)json_encode($context), 'RuntimeException')),
		);

		$result = $this->revoker($client, $bindings, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 1, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null, 'ended' => false], $result);
	}

	/** The group went with its last pad, and its sessions with it. */
	public function testAGroupGoneIsDone(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willThrowException(new EtherpadClientException('groupID does not exist'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		$result = $this->revoker($client, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => true], $result);
	}

	/**
	 * What failed is said as what it was - Etherpad's pads, the database's
	 * files, Etherpad's sessions - and each is asked again with a backoff.
	 *
	 * @return iterable<string,array{string,string}>
	 */
	public static function failures(): iterable {
		yield 'the pads' => ['listPads', 'Could not list the pads of a group whose Etherpad sessions to revoke.'];
		yield 'the files' => ['filesOfPads', GroupSessionRevoker::FILES_NOT_LOOKED_UP];
		yield 'the sessions' => ['listSessionsOfGroup', 'Could not list the Etherpad sessions of a group to revoke.'];
	}

	#[DataProvider('failures')]
	public function testWhatFailsIsSaidAndTriedAgain(string $failing, string $line): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturnCallback(static fn (): array => $failing === 'listPads' ? throw new EtherpadClientException('Connection timed out') : [self::PAD]);
		$client->method('listSessionsOfGroup')->willThrowException(new EtherpadClientException('Connection timed out'));
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('filesOfPads')->willReturnCallback(static fn (): array => $failing === 'filesOfPads'
			? throw new \RuntimeException('database gone')
			: [42]);
		$bindings->method('placesOf')->willReturn(self::TRASHED);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with($line);

		$result = $this->revoker($client, $bindings, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null, 'ended' => false], $result);
	}

	public function testLeavesWhatPassesTheCeilingToTheNextPass(): void {
		$sessions = [];
		for ($i = 1; $i <= 300; $i++) {
			$sessions['s.' . $i] = FixedClock::NOW + $i;
		}
		$client = $this->client($sessions);
		$client->expects(self::exactly(250))->method('deleteSession');

		$result = $this->revoker($client, $this->bindings(self::TRASHED))->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 250, 'remaining' => 50, 'retry' => false, 'nextDueAt' => null, 'ended' => false], $result);
	}

	public function testEndsThePassWithItsBudget(): void {
		$clock = new FixedClock();
		$client = $this->client(['s.1' => FixedClock::NOW + 1, 's.2' => FixedClock::NOW + 2, 's.3' => FixedClock::NOW + 3]);
		$client->expects(self::exactly(2))->method('deleteSession')->willReturnCallback(static function () use ($clock): void {
			$clock->advance(10);
		});

		$result = $this->revoker($client, $this->bindings(self::TRASHED), clock: $clock)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 2, 'remaining' => 1, 'retry' => false, 'nextDueAt' => null, 'ended' => false], $result);
	}

	/**
	 * Asked what the group holds, with no time left to list it: as slow as
	 * an outage, and backed off as one, so a pass that keeps running out
	 * ends rather than coming back every minute.
	 */
	public function testBacksOffWhenTheBudgetEndsBeforeTheListing(): void {
		$clock = new FixedClock();
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturnCallback(static function () use ($clock): array {
			$clock->advance(19);
			return [self::PAD];
		});
		$client->expects(self::never())->method('listSessionsOfGroup');
		// Said, as the failures beside it are: three such passes end the job.
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'No time left to list the Etherpad sessions of a group to revoke.',
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP),
		);

		$result = $this->revoker($client, $this->bindings(self::TRASHED), logger: $logger, clock: $clock)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null, 'ended' => false], $result);
	}

	/**
	 * A group that holds no pad - its last deleted, the group left over -
	 * opens nothing: its sessions go, with no file to ask about.
	 */
	public function testTakesTheSessionsOfAGroupHoldingNoPad(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturn([]);
		$client->method('listSessionsOfGroup')->willReturn(['s.left' => ['groupID' => self::GROUP, 'validUntil' => FixedClock::NOW + 3600]]);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			$removed[] = $id;
		});
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('filesOfPads')->with([])->willReturn([]);
		$bindings->method('placesOf')->with([])->willReturn([]);
		$bindings->method('anyInFiles')->with([])->willReturn(false);

		$result = $this->revoker($client, $bindings)->revokeRest(self::GROUP);

		self::assertSame(['s.left'], $removed);
		self::assertSame(['deleted' => 1, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => false], $result);
	}

	/**
	 * A large legacy group with every file in a trash: its files and where
	 * they are, and whether one is in Files, each asked once for all of
	 * them, so the pass reaches its sessions.
	 */
	public function testAsksOnceForAllTheFilesOfALargeGroup(): void {
		$pads = array_map(static fn (int $i): string => self::GROUP . '$legacy-' . $i, range(1, 1500));
		$files = range(1001, 2500);
		$places = array_combine($files, array_map(static fn (int $id): array => [3, 'files_trashbin/files/Pad ' . $id . '.pad.d1'], $files));
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturn($pads);
		$client->method('listSessionsOfGroup')->willReturn(['s.live' => ['groupID' => self::GROUP, 'validUntil' => FixedClock::NOW + 3600]]);
		$client->expects(self::once())->method('deleteSession')->with('s.live');
		$bindings = $this->createMock(BindingService::class);
		$bindings->expects(self::once())->method('filesOfPads')->with($pads)->willReturn($files);
		// Before the pass and before its delete, the mounts each time: a
		// batch for all of them, not a query a file.
		$bindings->expects(self::exactly(2))->method('placesOf')->with($files)->willReturn($places);
		$bindings->expects(self::exactly(2))->method('anyInFiles')->with($places)->willReturn(false);

		$result = $this->revoker($client, $bindings)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 1, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null, 'ended' => false], $result);
	}

	/** A refused delete is tried again; the line for it is SessionDeletes's, under the group. */
	public function testARefusedDeleteIsTriedAgain(): void {
		$client = $this->client(['s.refused' => FixedClock::NOW + 2, 's.fine' => FixedClock::NOW + 1]);
		$client->method('deleteSession')->willReturnCallback(static function (string $id): void {
			if ($id === 's.refused') {
				throw new EtherpadClientException('Etherpad API request failed: deleteSession');
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Could not revoke an Etherpad session of a group.',
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP),
		);

		$result = $this->revoker($client, $this->bindings(self::TRASHED), logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 1, 'remaining' => 1, 'retry' => true, 'nextDueAt' => null, 'ended' => false], $result);
	}

	/** @param array<string,int> $validUntil by session id */
	private function client(array $validUntil): EtherpadClient&MockObject {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturn([self::PAD]);
		// The job's revoke reads its listing whole, as the request's does.
		$client->method('listSessionsOfGroup')->with(self::GROUP, self::anything(), self::anything(), self::isNull())->willReturn(array_map(
			static fn (int $until): array => ['groupID' => self::GROUP, 'validUntil' => $until],
			$validUntil,
		));
		return $client;
	}

	/**
	 * The group's one pad, its file at $places - or where a callable says
	 * each time it is asked - and in Files as $inFiles says, else nowhere.
	 *
	 * @param array<int,array{int,string}>|\Closure(): array<int,array{int,string}> $places
	 * @param ?\Closure(int, string): bool $inFiles
	 */
	private function bindings(array|\Closure $places, ?\Closure $inFiles = null): BindingService {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('filesOfPads')->with([self::PAD])->willReturn([42]);
		$places instanceof \Closure
			? $bindings->method('placesOf')->with([42])->willReturnCallback($places)
			: $bindings->method('placesOf')->with([42])->willReturn($places);
		// Asked of every place, as each file's answer counts.
		$inFiles ??= static fn (): bool => false;
		$bindings->method('anyInFiles')->willReturnCallback(static function (array $places) use ($inFiles): bool {
			$any = false;
			foreach ($places as [$storage, $path]) {
				$any = $inFiles($storage, $path) || $any;
			}
			return $any;
		});
		return $bindings;
	}

	private function revoker(
		?EtherpadClient $client = null,
		?BindingService $bindings = null,
		?IJobList $jobList = null,
		?LoggerInterface $logger = null,
		?FixedClock $clock = null,
	): GroupSessionRevoker {
		$client ??= $this->createMock(EtherpadClient::class);
		$logger ??= $this->createMock(LoggerInterface::class);
		return new GroupSessionRevoker(
			$client,
			$bindings ?? $this->createMock(BindingService::class),
			new SessionDeletes($client, new ManagedPadLifecycle($client, $logger), $logger),
			$jobList ?? $this->createMock(IJobList::class),
			$logger,
			$clock ?? new FixedClock(),
		);
	}
}
