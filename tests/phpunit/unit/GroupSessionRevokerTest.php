<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGroupSessionsJob;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\Binding;
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

		$this->revoker(jobList: $jobList, logger: $logger)->queue(self::GROUP);
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
		self::assertSame(['deleted' => 3, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null], $result);
	}

	/**
	 * Every file of the group is asked about, one in a row owed a delete
	 * too - a file seen again makes it active - and one the file cache
	 * does not have is away without asking.
	 *
	 * @return iterable<string,array{string,array<int,array{int,string}>,list<array{int,string}>}>
	 */
	public static function away(): iterable {
		yield 'in a trash' => [BindingService::STATE_ACTIVE, self::TRASHED, [[3, 'files_trashbin/files/Notes.pad.d1791400000']]];
		yield 'owed a delete, in a trash' => [BindingService::STATE_PENDING_DELETE, self::TRASHED, [[3, 'files_trashbin/files/Notes.pad.d1791400000']]];
		yield 'out of the file cache' => [BindingService::STATE_ACTIVE, [], []];
	}

	/**
	 * @param array<int,array{int,string}> $places
	 * @param list<array{int,string}> $asked
	 */
	#[DataProvider('away')]
	public function testTakesThemWhileEveryFileIsAway(string $state, array $places, array $asked): void {
		$client = $this->client(['s.live' => FixedClock::NOW + 3600]);
		$client->expects(self::once())->method('deleteSession')->with('s.live');
		$seen = [];
		$bindings = $this->bindings($places, state: $state, inFiles: static function (int $storage, string $path) use (&$seen): bool {
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
		$bindings->method('findByPadId')->willReturnCallback(static fn (string $padId): ?Binding => match (true) {
			$padId !== self::PAD => new Binding(41, $padId, BindingService::ACCESS_PROTECTED, BindingService::STATE_ACTIVE),
			$case === 'none' => null,
			default => new Binding(42, $padId, BindingService::ACCESS_PROTECTED, BindingService::STATE_ACTIVE),
		});
		$bindings->method('placesOf')->willReturn([41 => [3, 'files_trashbin/files/Away.pad.d1'], 42 => [3, 'files/Notes.pad']]);
		$bindings->method('isInFiles')->willReturnCallback(static fn (int $storage, string $path): bool => $path === 'files/Notes.pad');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with($line, self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP));

		$result = $this->revoker($client, $bindings, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null], $result);
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
		self::assertSame(['deleted' => 1, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null], $result);
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

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null], $result);
	}

	/**
	 * Asking again is one look at where the files are; whether a user sees
	 * one in Files is asked again only when a file moved, once for each
	 * move - here within its trash, before the first delete.
	 */
	public function testAsksAboutTheMountsOnlyForAFileThatMoved(): void {
		$client = $this->client(['s.1' => FixedClock::NOW + 1, 's.2' => FixedClock::NOW + 2, 's.3' => FixedClock::NOW + 3, 's.4' => FixedClock::NOW + 4]);
		$client->expects(self::exactly(4))->method('deleteSession');
		$looks = 0;
		$asks = 0;
		$bindings = $this->bindings(static function () use (&$looks): array {
			return ++$looks === 1 ? self::TRASHED : [42 => [3, 'files_trashbin/files/Notes.pad.d1791400099']];
		}, inFiles: static function () use (&$asks): bool {
			$asks++;
			return false;
		});

		$this->revoker($client, $bindings)->revokeRest(self::GROUP);

		self::assertSame(5, $looks, 'once before the listing, then before each delete');
		self::assertSame(2, $asks, 'before the listing, and for the move');
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
			'Could not look up the files of a group\'s pads to revoke its Etherpad sessions.',
			self::callback(static fn (array $context): bool => $context['groupId'] === self::GROUP && str_contains((string)json_encode($context), 'RuntimeException')),
		);

		$result = $this->revoker($client, $bindings, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 1, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null], $result);
	}

	/** The group went with its last pad, and its sessions with it. */
	public function testAGroupGoneIsDone(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willThrowException(new EtherpadClientException('groupID does not exist'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		$result = $this->revoker($client, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => false, 'nextDueAt' => null], $result);
	}

	/**
	 * What failed is said as what it was - Etherpad's pads, the database's
	 * files, Etherpad's sessions - and each is asked again with a backoff.
	 *
	 * @return iterable<string,array{string,string}>
	 */
	public static function failures(): iterable {
		yield 'the pads' => ['listPads', 'Could not list the pads of a group whose Etherpad sessions to revoke.'];
		yield 'the files' => ['findByPadId', 'Could not look up the files of a group\'s pads to revoke its Etherpad sessions.'];
		yield 'the sessions' => ['listSessionsOfGroup', 'Could not list the Etherpad sessions of a group to revoke.'];
	}

	#[DataProvider('failures')]
	public function testWhatFailsIsSaidAndTriedAgain(string $failing, string $line): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturnCallback(static fn (): array => $failing === 'listPads' ? throw new EtherpadClientException('Connection timed out') : [self::PAD]);
		$client->method('listSessionsOfGroup')->willThrowException(new EtherpadClientException('Connection timed out'));
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('findByPadId')->willReturnCallback(static fn (): Binding => $failing === 'findByPadId'
			? throw new \RuntimeException('database gone')
			: new Binding(42, self::PAD, BindingService::ACCESS_PROTECTED, BindingService::STATE_ACTIVE));
		$bindings->method('placesOf')->willReturn(self::TRASHED);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with($line);

		$result = $this->revoker($client, $bindings, logger: $logger)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null], $result);
	}

	public function testLeavesWhatPassesTheCeilingToTheNextPass(): void {
		$sessions = [];
		for ($i = 1; $i <= 300; $i++) {
			$sessions['s.' . $i] = FixedClock::NOW + $i;
		}
		$client = $this->client($sessions);
		$client->expects(self::exactly(250))->method('deleteSession');

		$result = $this->revoker($client, $this->bindings(self::TRASHED))->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 250, 'remaining' => 50, 'retry' => false, 'nextDueAt' => null], $result);
	}

	public function testEndsThePassWithItsBudget(): void {
		$clock = new FixedClock();
		$client = $this->client(['s.1' => FixedClock::NOW + 1, 's.2' => FixedClock::NOW + 2, 's.3' => FixedClock::NOW + 3]);
		$client->expects(self::exactly(2))->method('deleteSession')->willReturnCallback(static function () use ($clock): void {
			$clock->advance(10);
		});

		$result = $this->revoker($client, $this->bindings(self::TRASHED), clock: $clock)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 2, 'remaining' => 1, 'retry' => false, 'nextDueAt' => null], $result);
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

		$result = $this->revoker($client, $this->bindings(self::TRASHED), clock: $clock)->revokeRest(self::GROUP);

		self::assertSame(['deleted' => 0, 'remaining' => 0, 'retry' => true, 'nextDueAt' => null], $result);
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

		self::assertSame(['deleted' => 1, 'remaining' => 1, 'retry' => true, 'nextDueAt' => null], $result);
	}

	/** @param array<string,int> $validUntil by session id */
	private function client(array $validUntil): EtherpadClient&MockObject {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturn([self::PAD]);
		$client->method('listSessionsOfGroup')->willReturn(array_map(
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
	private function bindings(array|\Closure $places, string $state = BindingService::STATE_ACTIVE, ?\Closure $inFiles = null): BindingService {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('findByPadId')->willReturn(new Binding(42, self::PAD, BindingService::ACCESS_PROTECTED, $state));
		$places instanceof \Closure
			? $bindings->method('placesOf')->with([42])->willReturnCallback($places)
			: $bindings->method('placesOf')->with([42])->willReturn($places);
		$bindings->method('isInFiles')->willReturnCallback($inFiles ?? static fn (): bool => false);
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
