<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Exception\LegacyPadNotFoundException;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\LegacyProtectedImportDisabledException;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
use OCA\EtherpadNextcloud\Service\LegacyImportPolicy;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadLegacyMigrationService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadLegacyMigrationServiceTest extends TestCase {
	public function testCrossOriginShortcutRoutesThroughExternalSeed(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(101);

		$externalPadSeeder = $this->createMock(ExternalPadSeeder::class);
		$externalPadSeeder->expects($this->once())
			->method('seed')
			->with($file, 101, 'https://legacy.example.test/p/team-meeting')
			->willReturn([
				'file_id' => 101,
				'pad_id' => 'ext.team-meeting',
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'pad_url' => 'https://legacy.example.test/p/team-meeting',
			]);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')
			->with('https://legacy.example.test/p/team-meeting')
			->willReturn('https://legacy.example.test');

		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('findByPadId');
		$binding->expects($this->never())->method('createBinding');

		$this->buildService(
			binding: $binding,
			etherpadClient: $etherpadClient,
			externalPadSeeder: $externalPadSeeder,
		)->migrate('alice', $file, [
			'url' => 'https://legacy.example.test/p/team-meeting',
			'pad_id' => 'team-meeting',
		]);
	}

	public function testSameOriginPublicNoCollisionRebinds(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(202);
		$file->expects($this->once())->method('putContent')->with('written-frontmatter');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')
			->with('team-pad')
			->willReturn(BindingService::ACCESS_PUBLIC);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with(202, 'team-pad', BindingService::ACCESS_PUBLIC, null, 'https://pad.our-server.test/p/team-pad')
			->willReturn('written-frontmatter');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('buildPadUrl')->with('team-pad')->willReturn('https://pad.our-server.test/p/team-pad');

		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->once())
			->method('findByPadId')
			->with('team-pad', BindingService::STATE_ACTIVE)
			->willReturn(null);
		$binding->expects($this->once())
			->method('createBinding')
			->with(202, 'team-pad', BindingService::ACCESS_PUBLIC);

		$this->buildService(
			logger: $this->migrationLine('info', 'Migrated legacy Ownpad .pad as managed pad (re-bind).', 202, BindingService::ACCESS_PUBLIC, 'alice', 'none'),
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/team-pad',
			'pad_id' => 'team-pad',
		]);
	}

	public function testSameOriginProtectedNoCollisionRebindsAsProtected(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(303);
		$file->expects($this->once())->method('putContent');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')
			->with('g.abcdefghijklmnop$team-meeting')
			->willReturn(BindingService::ACCESS_PROTECTED);
		$padFileService->method('buildInitialDocument')->willReturn('frontmatter');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.our-server.test/p/g.abcdefghijklmnop$team-meeting');
		// The pad a legacy file names has to be in the group it names.
		$etherpadClient->method('listPads')->with('g.abcdefghijklmnop')->willReturn(['g.abcdefghijklmnop$team-meeting']);

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(null);
		$binding->expects($this->once())
			->method('createBinding')
			->with(303, 'g.abcdefghijklmnop$team-meeting', BindingService::ACCESS_PROTECTED);

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.abcdefghijklmnop$team-meeting',
			'pad_id' => 'g.abcdefghijklmnop$team-meeting',
		]);
	}

	public function testSameOriginCollisionWithAccessWritesFrontmatterOnly(): void {
		// Pad-id already bound to file 999 owned by a user with whom alice
		// shares read access. Migrate the file's content into our format but
		// do NOT create a second binding row — the file becomes a copy.
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(404);
		$file->expects($this->once())->method('putContent');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')->willReturn(BindingService::ACCESS_PROTECTED);
		$padFileService->method('buildInitialDocument')->willReturn('frontmatter');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.our-server.test/p/g.abcdefghijklmnop$x');
		// The pad a legacy file names has to be in the group it names.
		$etherpadClient->method('listPads')->with('g.abcdefghijklmnop')->willReturn(['g.abcdefghijklmnop$x']);

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(new Binding(fileId: 999, padId: 'g.abcdefghijklmnop$x', accessMode: 'protected', state: BindingService::STATE_ACTIVE));
		$binding->expects($this->never())->method('createBinding');

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->once())
			->method('resolveUserFileNodeById')
			->with('alice', 999)
			->willReturn($this->createMock(File::class));

		$this->buildService(
			logger: $this->migrationLine('info', 'Migrated legacy Ownpad .pad as copy of an already-bound pad.', 404, BindingService::ACCESS_PROTECTED, 'alice', 'with_access', ['boundFileId' => 999]),
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			resolver: $resolver,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.abcdefghijklmnop$x',
			'pad_id' => 'g.abcdefghijklmnop$x',
		]);
	}

	public function testSameOriginNoCollisionCreatesBindingBeforeFile(): void {
		// Binding first: managed frontmatter with no binding row leaves the
		// copy-recovery flow nothing to work with.
		$callOrder = [];

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(606);
		$file->method('putContent')->willReturnCallback(static function () use (&$callOrder): void {
			$callOrder[] = 'putContent';
		});

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')->willReturn(BindingService::ACCESS_PROTECTED);
		$padFileService->method('buildInitialDocument')->willReturn('frontmatter');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.our-server.test/p/g.xxxxxxxxxxxxxxxx$y');
		// The pad a legacy file names has to be in the group it names.
		$etherpadClient->method('listPads')->with('g.xxxxxxxxxxxxxxxx')->willReturn(['g.xxxxxxxxxxxxxxxx$y']);

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(null);
		$binding->expects($this->once())
			->method('createBinding')
			->willReturnCallback(static function () use (&$callOrder): void {
				$callOrder[] = 'createBinding';
			});

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.xxxxxxxxxxxxxxxx$y',
			'pad_id' => 'g.xxxxxxxxxxxxxxxx$y',
		]);

		$this->assertSame(['createBinding', 'putContent'], $callOrder);
	}

	public function testSameOriginBindingRaceReclassifiesAsCollisionWithAccess(): void {
		// A concurrent migration / open created the binding between our
		// findByPadId and our createBinding. The second findByPadId now
		// finds the winning binding; if we can read that file we recover
		// as a copy-of-a-pad without writing a second binding row.
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(707);
		$file->expects($this->once())->method('putContent');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')->willReturn(BindingService::ACCESS_PUBLIC);
		$padFileService->method('buildInitialDocument')->willReturn('frontmatter');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.our-server.test/p/race-pad');

		$binding = $this->createMock(BindingService::class);
		$findCalls = 0;
		$binding->method('findByPadId')->willReturnCallback(
			static function () use (&$findCalls): ?Binding {
				$findCalls++;
				if ($findCalls === 1) {
					return null; // initial check: no binding
				}
				return new Binding(808, 'race-pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE);
			}
		);
		$binding->expects($this->once())
			->method('createBinding')
			->willThrowException(new BindingException('unique constraint hit'));

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->once())
			->method('resolveUserFileNodeById')
			->with('alice', 808)
			->willReturn($this->createMock(File::class));

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			resolver: $resolver,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/race-pad',
			'pad_id' => 'race-pad',
		]);
	}

	/**
	 * The pad id in a legacy `.pad` is written by whoever wrote the file. A
	 * real one names an existing pad, which collides with that pad's binding
	 * — `pad_id` is unique — and comes out as a collision. An invented
	 * suffix collides with nothing while still naming a real group, and the
	 * session minted on open would grant access to everything in it.
	 */
	public function testSameOriginRefusesAPadThatIsNotInTheGroupItNames(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(506);
		$file->expects($this->never())->method('putContent');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')->willReturn(BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->expects($this->once())
			->method('listPads')
			->with('g.VICTIMGROUPID123')
			->willReturn(['g.VICTIMGROUPID123$their-real-pad']);

		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('createBinding');

		// A format problem to everything that catches those, with a sentence
		// of its own: the file is well-formed, the pad is not there.
		$this->expectException(LegacyPadNotFoundException::class);
		$this->expectExceptionMessage('The legacy .pad file names a pad its Etherpad group does not hold.');

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			logger: $this->refusalLine('the pad it names cannot be linked.', 506, BindingService::ACCESS_PROTECTED, 'mallory', ['reason' => 'not_in_group']),
		)->migrate('mallory', $file, [
			'url' => 'https://pad.our-server.test/p/g.VICTIMGROUPID123$made-up',
			'pad_id' => 'g.VICTIMGROUPID123$made-up',
		]);
	}

	/**
	 * A group listing Etherpad refuses is refused like a missing pad: the
	 * answer does not tell whether the group is there.
	 */
	public function testSameOriginRefusesAPadWhoseGroupEtherpadDoesNotList(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(511);
		$file->expects($this->never())->method('putContent');
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')->willReturn(BindingService::ACCESS_PROTECTED);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('listPads')->willThrowException(new EtherpadRefusedException('Etherpad API error (listPads): groupID does not exist'));
		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('createBinding');

		$this->expectException(LegacyPadNotFoundException::class);

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			logger: $this->refusalLine('the pad it names cannot be linked.', 511, BindingService::ACCESS_PROTECTED, 'mallory', ['reason' => 'not_in_group']),
		)->migrate('mallory', $file, [
			'url' => 'https://pad.our-server.test/p/g.NOSUCHGROUP00000$notes',
			'pad_id' => 'g.NOSUCHGROUP00000$notes',
		]);
	}

	/** @return iterable<string, array{string, string}> */
	public static function idsNotToLink(): iterable {
		yield 'a group of fifteen' => ['g.VICTIMGROUPID12$notes', BindingService::ACCESS_PROTECTED];
		yield 'a colon' => ['a:b', BindingService::ACCESS_PUBLIC];
		// Etherpad takes these; a `.pad` file's frontmatter cannot hold them.
		yield 'a line break' => ["a\nb", BindingService::ACCESS_PUBLIC];
		yield 'a carriage return' => ["a\rb", BindingService::ACCESS_PUBLIC];
		yield 'a NUL' => ["a\x00b", BindingService::ACCESS_PUBLIC];
	}

	/**
	 * An id Etherpad's API refuses, or one a `.pad` file cannot hold, is not
	 * linked: refused before the database or Etherpad is asked anything,
	 * with the migration's own line, the file left as it is. The format is
	 * the real one, whose own rule refuses a line break or NUL. Which ids
	 * Etherpad refuses is PadIdTest's.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('idsNotToLink')]
	public function testSameOriginRefusesAPadIdItCannotLink(string $padId, string $accessMode): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(507);
		$file->expects($this->never())->method('putContent');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->expects($this->never())->method('listPads');

		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('findByPadId');
		$binding->expects($this->never())->method('createBinding');

		$this->expectException(LegacyPadNotFoundException::class);
		$this->expectExceptionMessage('The legacy .pad file names a pad id that cannot be linked.');

		$this->buildService(
			binding: $binding,
			padFileService: new PadFileService(new FixedClock()),
			etherpadClient: $etherpadClient,
			logger: $this->refusalLine('the pad it names cannot be linked.', 507, $accessMode, 'mallory', ['reason' => 'invalid_id']),
		)->migrate('mallory', $file, [
			'url' => 'https://pad.our-server.test/p/' . rawurlencode($padId),
			'pad_id' => $padId,
		]);
	}

	/**
	 * An id the app cannot link is refused before anything else is asked,
	 * the switch for group pads included: whatever the switch says, such
	 * a pad could not be linked.
	 */
	public function testAnIdItCannotLinkIsRefusedBeforeTheSwitch(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(508);
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')->willReturn(BindingService::ACCESS_PROTECTED);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(null);

		$this->expectException(LegacyPadNotFoundException::class);

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			legacyImportPolicy: $this->policyAllowing(false),
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.short$notes',
			'pad_id' => 'g.short$notes',
		]);
	}

	/**
	 * A row an earlier version made for this file, under an id the app
	 * cannot link, is not finished: the id is refused before the row is
	 * looked up, and the row is left as it is.
	 */
	public function testAHalfMigratedFileUnderAnIdItCannotLinkIsRefused(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(509);
		$file->expects($this->never())->method('putContent');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('findByPadId');
		$binding->expects($this->never())->method('deleteActiveBinding');

		$this->expectException(LegacyPadNotFoundException::class);

		$this->buildService(
			binding: $binding,
			padFileService: new PadFileService(new FixedClock()),
			etherpadClient: $etherpadClient,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/a:b',
			'pad_id' => 'a:b',
		]);
	}

	/**
	 * A pad on another server is that server's to judge: the migration
	 * hands it to the external route, not refusing it for its id.
	 */
	public function testAPadOnAnotherServerIsNotAskedTheRule(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(510);
		$externalPadSeeder = $this->createMock(ExternalPadSeeder::class);
		$externalPadSeeder->expects($this->once())->method('seed')->with($file, 510, 'https://legacy.example.test/p/a:b');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://legacy.example.test');

		$this->buildService(
			etherpadClient: $etherpadClient,
			externalPadSeeder: $externalPadSeeder,
		)->migrate('alice', $file, [
			'url' => 'https://legacy.example.test/p/a:b',
			'pad_id' => 'a:b',
		]);
	}

	/**
	 * Only Etherpad not knowing the group counts as the group not listing
	 * the pad; any other refusal of the listing goes on as it is, for the
	 * error mapper to report what Etherpad said.
	 */
	public function testAnotherRefusalOfTheListingGoesOn(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(512);
		$file->expects($this->never())->method('putContent');
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')->willReturn(BindingService::ACCESS_PROTECTED);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$refused = new EtherpadRefusedException('Etherpad API error (listPads): internal error');
		$etherpadClient->method('listPads')->willThrowException($refused);
		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('createBinding');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		try {
			$this->buildService(
				binding: $binding,
				padFileService: $padFileService,
				etherpadClient: $etherpadClient,
				logger: $logger,
			)->migrate('alice', $file, [
				'url' => 'https://pad.our-server.test/p/g.abcdefghijklmnop$x',
				'pad_id' => 'g.abcdefghijklmnop$x',
			]);
			$this->fail('the refusal goes on');
		} catch (EtherpadRefusedException $e) {
			$this->assertSame($refused, $e);
		}
	}

	/** Fails closed: a read that did not answer is not permission to bind. */
	public function testSameOriginRefusesWhenTheGroupCannotBeRead(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(507);
		$file->expects($this->never())->method('putContent');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		// As the client throws it: an outage, not a refusal, goes on as it is.
		$etherpadClient->method('listPads')->willThrowException(new EtherpadClientException('Etherpad API request failed: listPads', 0, new \RuntimeException('Connection timed out')));

		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('createBinding');

		$this->expectException(EtherpadClientException::class);

		$this->buildService(
			binding: $binding,
			padFileService: $this->createMock(PadFileService::class),
			etherpadClient: $etherpadClient,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.abcdefghijklmnop$x',
			'pad_id' => 'g.abcdefghijklmnop$x',
		]);
	}

	public function testSameOriginCollisionWithoutAccessRefuses(): void {
		// The pad is bound to someone else's file alice cannot read.
		// Migration is refused, the .pad file stays untouched, an exception
		// surfaces to the controller layer.
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(505);
		$file->expects($this->never())->method('putContent');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')->willReturn(BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		// The pad is real and in its group — what refuses this migration is
		// the other file's binding, not the group check.
		$etherpadClient->method('listPads')->with('g.abcdefghijklmnop')->willReturn(['g.abcdefghijklmnop$x']);

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(new Binding(fileId: 888, padId: 'g.abcdefghijklmnop$x', accessMode: 'protected', state: BindingService::STATE_ACTIVE));
		$binding->expects($this->never())->method('createBinding');

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')
			->willThrowException(new NotFoundException('no access'));

		$this->expectException(LegacyPadCollisionException::class);
		$this->expectExceptionMessage('This pad is already linked to another file you do not have access to.');

		$this->buildService(
			logger: $this->migrationLine('warning', 'Refused legacy Ownpad migration — pad already bound to a file the user cannot read.', 505, BindingService::ACCESS_PROTECTED, 'alice', 'no_access', ['boundFileId' => 888]),
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			resolver: $resolver,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.abcdefghijklmnop$x',
			'pad_id' => 'g.abcdefghijklmnop$x',
		]);
	}

	private function buildService(
		?BindingService $binding = null,
		?PadFileService $padFileService = null,
		?EtherpadClient $etherpadClient = null,
		?ExternalPadSeeder $externalPadSeeder = null,
		?UserNodeResolver $resolver = null,
		?LegacyImportPolicy $legacyImportPolicy = null,
		?LoggerInterface $logger = null,
	): PadLegacyMigrationService {
		return new PadLegacyMigrationService(
			$binding ?? $this->createMock(BindingService::class),
			$legacyImportPolicy ?? $this->allowingPolicy(),
			$padFileService ?? $this->createMock(PadFileService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadSeeder ?? $this->createMock(ExternalPadSeeder::class),
			$resolver ?? $this->createMock(UserNodeResolver::class),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * Existing bindings are left alone, so a migration that made the row and
	 * died before the file write can still finish.
	 */
	public function testFinishesAHalfMigratedFileEvenWithProtectedImportSwitchedOff(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(204);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')
			->willReturn(BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('listPads')->willReturn(['g.ourgroup00000000$notes']);

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(new Binding(fileId: 204, padId: 'g.ourgroup00000000$notes', accessMode: BindingService::ACCESS_PROTECTED, state: BindingService::STATE_ACTIVE));
		$binding->expects($this->never())->method('createBinding');
		$file->expects($this->once())->method('putContent');

		$this->buildService(
			logger: $this->migrationLine('info', 'Migrated legacy Ownpad .pad — finishing partially-completed prior migration.', 204, BindingService::ACCESS_PROTECTED, 'alice', 'self'),
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			legacyImportPolicy: $this->policyAllowing(false),
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.ourgroup00000000$notes',
			'pad_id' => 'g.ourgroup00000000$notes',
		]);
	}

	/**
	 * A logger that takes one refusal, with the fields every refusal where
	 * no row names the pad yet has - and nothing more: not the URL, nor the
	 * pad id.
	 *
	 * @param array<string,string> $more
	 */
	private function refusalLine(string $why, int $fileId, string $accessMode, string $uid, array $more = []): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('Refused legacy Ownpad migration - ' . $why, [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceHost' => 'https://pad.our-server.test',
				'originBranch' => 'same',
				'accessMode' => $accessMode,
				'collision' => 'none',
				'uid' => $uid,
				...$more,
			]);
		return $logger;
	}

	/**
	 * A logger that takes one line of a same-origin migration, with every
	 * field such a line has.
	 *
	 * @param array<string,int> $more
	 */
	private function migrationLine(string $level, string $message, int $fileId, string $accessMode, string $uid, string $collision, array $more = []): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method($level)
			->with($message, [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceHost' => 'https://pad.our-server.test',
				'originBranch' => 'same',
				'accessMode' => $accessMode,
				'collision' => $collision,
				'uid' => $uid,
				...$more,
			]);
		return $logger;
	}

	private function policyAllowing(bool $protectedImport): LegacyImportPolicy {
		$policy = $this->createMock(LegacyImportPolicy::class);
		$policy->method('allowsProtectedImport')->willReturn($protectedImport);
		return $policy;
	}

	private function allowingPolicy(): LegacyImportPolicy {
		return $this->policyAllowing(true);
	}

	public function testRefusesAGroupPadWhenProtectedImportIsSwitchedOff(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(202);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')
			->willReturn(BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		// Nothing is asked of Etherpad: whether the pad is there, whether
		// the group is, and whether the import is allowed would otherwise
		// come back as three different responses.
		$etherpadClient->expects($this->never())->method('listPads');

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(null);
		$binding->expects($this->never())->method('createBinding');
		// The placement is justified by the file surviving untouched, so
		// that switching the setting on migrates it on the next open.
		$file->expects($this->never())->method('putContent');

		$this->expectException(LegacyProtectedImportDisabledException::class);

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			legacyImportPolicy: $this->policyAllowing(false),
			logger: $this->refusalLine('protected import is switched off.', 202, BindingService::ACCESS_PROTECTED, 'mallory'),
		)->migrate('mallory', $file, [
			'url' => 'https://pad.our-server.test/p/g.someoneelses0000$notes',
			'pad_id' => 'g.someoneelses0000$notes',
		]);
	}

	public function testStillImportsAPublicPadWhenProtectedImportIsSwitchedOff(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(203);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('inferAccessModeFromPadId')
			->willReturn(BindingService::ACCESS_PUBLIC);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(null);
		// The switch is about group pads. A pad anyone holding its id can
		// read is no more reachable for having a file bound to it.
		$binding->expects($this->once())->method('createBinding');
		$file->expects($this->once())->method('putContent');

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			legacyImportPolicy: $this->policyAllowing(false),
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/team-notes',
			'pad_id' => 'team-notes',
		]);
	}
}
