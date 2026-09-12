<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\LegacyProtectedImportDisabledException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
use OCA\EtherpadNextcloud\Service\LegacyImportPolicy;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadLegacyMigrationService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
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
			->with('g.abc$team-meeting')
			->willReturn(BindingService::ACCESS_PROTECTED);
		$padFileService->method('buildInitialDocument')->willReturn('frontmatter');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.our-server.test/p/g.abc$team-meeting');
		// The pad a legacy file names has to be in the group it names.
		$etherpadClient->method('listPads')->with('g.abc')->willReturn(['g.abc$team-meeting']);

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(null);
		$binding->expects($this->once())
			->method('createBinding')
			->with(303, 'g.abc$team-meeting', BindingService::ACCESS_PROTECTED);

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.abc$team-meeting',
			'pad_id' => 'g.abc$team-meeting',
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
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.our-server.test/p/g.abc$x');
		// The pad a legacy file names has to be in the group it names.
		$etherpadClient->method('listPads')->with('g.abc')->willReturn(['g.abc$x']);

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn([
			'file_id' => 999,
			'pad_id' => 'g.abc$x',
			'access_mode' => 'protected',
		]);
		$binding->expects($this->never())->method('createBinding');

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->once())
			->method('resolveUserFileNodeById')
			->with('alice', 999)
			->willReturn($this->createMock(File::class));

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			resolver: $resolver,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.abc$x',
			'pad_id' => 'g.abc$x',
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
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.our-server.test/p/g.x$y');
		// The pad a legacy file names has to be in the group it names.
		$etherpadClient->method('listPads')->with('g.x')->willReturn(['g.x$y']);

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
			'url' => 'https://pad.our-server.test/p/g.x$y',
			'pad_id' => 'g.x$y',
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
			static function () use (&$findCalls): ?array {
				$findCalls++;
				if ($findCalls === 1) {
					return null; // initial check: no binding
				}
				return ['file_id' => 808, 'pad_id' => 'race-pad', 'access_mode' => 'public'];
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

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->expects($this->once())
			->method('listPads')
			->with('g.VICTIMGROUPID12')
			->willReturn(['g.VICTIMGROUPID12$their-real-pad']);

		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('createBinding');

		$this->expectException(PadFileFormatException::class);

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
		)->migrate('mallory', $file, [
			'url' => 'https://pad.our-server.test/p/g.VICTIMGROUPID12$made-up',
			'pad_id' => 'g.VICTIMGROUPID12$made-up',
		]);
	}

	/** Fails closed: a read that did not answer is not permission to bind. */
	public function testSameOriginRefusesWhenTheGroupCannotBeRead(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(507);
		$file->expects($this->never())->method('putContent');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getConfiguredOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('normalizeOrigin')->willReturn('https://pad.our-server.test');
		$etherpadClient->method('listPads')->willThrowException(new \RuntimeException('Connection timed out'));

		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('createBinding');

		$this->expectException(\RuntimeException::class);

		$this->buildService(
			binding: $binding,
			padFileService: $this->createMock(PadFileService::class),
			etherpadClient: $etherpadClient,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.abc$x',
			'pad_id' => 'g.abc$x',
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
		$etherpadClient->method('listPads')->with('g.abc')->willReturn(['g.abc$x']);

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn([
			'file_id' => 888,
			'pad_id' => 'g.abc$x',
			'access_mode' => 'protected',
		]);
		$binding->expects($this->never())->method('createBinding');

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')
			->willThrowException(new NotFoundException('no access'));

		$this->expectException(LegacyPadCollisionException::class);
		$this->expectExceptionMessage('This pad is already linked to another file you do not have access to.');

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			resolver: $resolver,
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.abc$x',
			'pad_id' => 'g.abc$x',
		]);
	}

	private function buildService(
		?BindingService $binding = null,
		?PadFileService $padFileService = null,
		?EtherpadClient $etherpadClient = null,
		?ExternalPadSeeder $externalPadSeeder = null,
		?UserNodeResolver $resolver = null,
		?LegacyImportPolicy $legacyImportPolicy = null,
	): PadLegacyMigrationService {
		return new PadLegacyMigrationService(
			$binding ?? $this->createMock(BindingService::class),
			$legacyImportPolicy ?? $this->allowingPolicy(),
			$padFileService ?? $this->createMock(PadFileService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadSeeder ?? $this->createMock(ExternalPadSeeder::class),
			$resolver ?? $this->createMock(UserNodeResolver::class),
			$this->createMock(LoggerInterface::class),
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
		$etherpadClient->method('listPads')->willReturn(['g.ourgroup$notes']);

		$binding = $this->createMock(BindingService::class);
		$binding->method('findByPadId')->willReturn(['file_id' => 204, 'pad_id' => 'g.ourgroup$notes']);
		$binding->expects($this->never())->method('createBinding');
		$file->expects($this->once())->method('putContent');

		$this->buildService(
			binding: $binding,
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			legacyImportPolicy: $this->policyAllowing(false),
		)->migrate('alice', $file, [
			'url' => 'https://pad.our-server.test/p/g.ourgroup$notes',
			'pad_id' => 'g.ourgroup$notes',
		]);
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
		)->migrate('mallory', $file, [
			'url' => 'https://pad.our-server.test/p/g.someone-elses$notes',
			'pad_id' => 'g.someone-elses$notes',
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
