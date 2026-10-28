<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\FileCreatedFromTemplateListener;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Service\ClaimedFileWriter;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCA\EtherpadNextcloud\Tests\Support\BuildsAppAccess;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\NotPermittedException;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener handles four cases, one per thing the picker can offer:
 *  - blank entry → write fresh frontmatter via PadBootstrapService so /open
 *    doesn't 4xx on the first call
 *  - pad type tile → the same, straight to that type
 *  - external tile → link the file to the address the picker collected
 *  - any other template → PadCreationService::materializeTemplateInto
 */
class FileCreatedFromTemplateListenerTest extends TestCase {
	use BuildsAppAccess;

	private const AN_ADDRESS = ['pad_url' => ['content' => 'https://pad.remote.test/p/RemotePad']];

	/** Writes, finds and removes the claimed target for the listener. */
	private ClaimedFileWriter $writer;

	protected function setUp(): void {
		$this->writer = $this->createMock(ClaimedFileWriter::class);
	}

	public function testIgnoresUnrelatedEvent(): void {
		$creation = $this->createMock(PadCreationService::class);
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)->handle(new class extends Event {});
	}

	public function testIgnoresNonPadTarget(): void {
		$creation = $this->createMock(PadCreationService::class);
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent(
			$this->file('Template.pad'),
			$this->file('Notes.txt'),
			[],
		));
	}

	/**
	 * Neither carries content: the file is initialised straight away, to the
	 * pad type a tile offers, so its first open finds frontmatter.
	 *
	 * @return iterable<string,array{?string,?string}> the template the picker hands over, and the pad type it offers
	 */
	public static function createsThatGoStraightToInitialisation(): iterable {
		yield 'the blank entry' => [null, null];
		yield 'the tile of a pad type' => [PadTemplateStorage::PUBLIC_TILE_NAME, 'public'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('createsThatGoStraightToInitialisation')]
	public function testInitialisesStraightToThePadType(?string $templateName, ?string $mode): void {
		$target = $this->file('New.pad');

		$creation = $this->createMock(PadCreationService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $target, '', $mode);

		$this->buildListener($creation, $bootstrap, true, $this->tilesOffering($mode))->handle(new FileCreatedFromTemplateEvent(
			$templateName === null ? null : $this->file($templateName),
			$target,
			[],
		));
	}

	public function testSourceTemplateDelegatesToCreationService(): void {
		$template = $this->file('Tpl.pad');
		$target = $this->untouchedTarget('New.pad');

		$claim = new CreatedFileClaim('alice', 4242);
		$creation = $this->createMock(PadCreationService::class);
		$creation->method('claimTemplateTarget')->with($this->identicalTo($target), 'alice')->willReturn($claim);
		$creation->expects($this->once())
			->method('materializeTemplateInto')
			->with($this->identicalTo($template), $this->isInstanceOf(IUser::class), $this->identicalTo($claim));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	public function testFailedSourceTemplateFallsBackToBlankInit(): void {
		$template = $this->file('Tpl.pad');
		$target = $this->untouchedTarget('New.pad');
		$byId = $this->file('New.pad');
		$byId->expects($this->once())->method('putContent')->with('');

		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $target, $byId);
		$creation->method('materializeTemplateInto')
			->willThrowException(new \RuntimeException('boom'));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $this->identicalTo($byId), '');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Pad template materialization failed — falling back to blank-pad init.',
			$this->callback(static fn (array $context): bool => [$context['app'], $context['fileId'], $context['templateFileId']] === ['etherpad_nextcloud', 4242, 42]),
		);

		$this->buildListener($creation, $bootstrap, logger: $logger)
			->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	public function testResetsTargetWhenNoUserInSession(): void {
		$template = $this->file('Tpl.pad');
		$target = $this->file('New.pad');
		$target->expects($this->once())->method('putContent')->with('');

		$creation = $this->createMock(PadCreationService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$listener = $this->buildListener($creation, $bootstrap, withUser: false);
		$listener->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	/**
	 * The picker supplies the address as a template field; the listener
	 * trims it and writes the prepared document through the file's claim.
	 */
	public function testSeedsAFileFromTheExternalTileWithTheAddressThePickerAsked(): void {
		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->expects($this->once())
			->method('prepare')
			->with(4242, 'https://pad.remote.test/p/RemotePad')
			->willReturn($this->prepared());

		$target = $this->untouchedTarget('Team pad.pad');
		$creation = $this->createMock(PadCreationService::class);
		$claim = $this->expectClaimFor($creation, $target);
		$this->writer->expects($this->once())->method('write')->with($claim, 'the document');

		$this->createFromTheExternalTile($creation, $seeder, $target, [
			'pad_url' => ['content' => ' https://pad.remote.test/p/RemotePad '],
		]);
	}

	/**
	 * A public pad's URL is its access link, and the pad id in it is enough to
	 * open the pad — so the record of an unavailable export names the host and
	 * the file, nothing that hands the pad to whoever reads the log.
	 */
	public function testKeepsThePadOutOfTheLogWhenTheRemoteExportIsUnavailable(): void {
		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->method('prepare')->willReturn($this->prepared(['snapshot_warning_code' => 'remote_export_unavailable']));
		$target = $this->file('Team pad.pad');
		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $target);

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = $context;
			}
		);

		$this->createFromTheExternalTile($creation, $seeder, $target, [
			'pad_url' => ['content' => 'https://pad.remote.test/p/RemotePad?token=s3cret#top'],
		], $logger);

		$this->assertCount(1, $logged);
		$this->assertSame('etherpad_nextcloud', $logged[0]['app'] ?? null);
		$this->assertSame('pad.remote.test', $logged[0]['padHost'] ?? null);
		$this->assertSame(4242, $logged[0]['fileId'] ?? null);
		$this->assertStringNotContainsString('RemotePad', json_encode($logged[0]));
		$this->assertStringNotContainsString('s3cret', json_encode($logged[0]));
	}

	/**
	 * @return iterable<string,array{array<string,mixed>,?string}> the template fields, and how seeding their address fails (null: it is not tried)
	 */
	public static function externalCreatesThatCannotBeLinked(): iterable {
		yield 'field missing' => [[], null];
		yield 'field empty' => [['pad_url' => ['content' => '   ']], null];
		yield 'address cannot be seeded' => [self::AN_ADDRESS, 'pad not reachable'];
	}

	/** @param array<string,mixed> $fields */
	#[\PHPUnit\Framework\Attributes\DataProvider('externalCreatesThatCannotBeLinked')]
	public function testRemovesTheFileThatCannotBeLinked(array $fields, ?string $seedFailure): void {
		$failure = new \RuntimeException($seedFailure ?? 'No pad address was given for the external pad template.');
		$seeder = $this->createMock(ExternalPadSeeder::class);
		if ($seedFailure === null) {
			$seeder->expects($this->never())->method('prepare');
		} else {
			$seeder->expects($this->once())->method('prepare')->willThrowException($failure);
		}
		$target = $this->untouchedTarget('Team pad.pad');
		$creation = $this->createMock(PadCreationService::class);
		$claim = $this->expectClaimFor($creation, $target);
		$this->writer->expects($this->never())->method('write');
		$this->writer->expects($this->once())->method('deleteIfUnchanged')->with($this->identicalTo($claim));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Could not link a new .pad file to an external pad.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === 4242),
		);

		try {
			$this->createFromTheExternalTile($creation, $seeder, $target, $fields, $logger);
			$this->fail('the failure goes on');
		} catch (\RuntimeException $e) {
			// The seed's failure goes on as it is; a missing address is the listener's own refusal.
			if ($seedFailure === null) {
				$this->assertEquals($failure, $e);
			} else {
				$this->assertSame($failure, $e);
			}
		}
	}

	/** The file's id cannot be read, so the remote pad is not asked either. */
	public function testLeavesTheFileWhenItCannotBeClaimed(): void {
		$target = $this->file('Team pad.pad');
		$target->expects($this->never())->method('delete');

		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->expects($this->never())->method('prepare');
		$failure = new \RuntimeException('Could not resolve new file ID.');
		$creation = $this->createMock(PadCreationService::class);
		$creation->method('claimTemplateTarget')->with($this->identicalTo($target), 'alice')->willThrowException($failure);
		$this->writer->expects($this->never())->method('write');
		$this->writer->expects($this->never())->method('deleteIfUnchanged');

		try {
			$this->createFromTheExternalTile($creation, $seeder, $target);
			$this->fail('the failure goes on');
		} catch (\RuntimeException $e) {
			$this->assertSame($failure, $e);
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('createsThatGoStraightToInitialisation')]
	public function testRemovesTheFileWhenNoPadTypeIsEnabled(?string $templateName, ?string $mode): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->once())->method('delete');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willThrowException(new PadTypeDisabledException());

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($this->createMock(PadCreationService::class), $bootstrap, true, $this->tilesOffering($mode))
			->handle(new FileCreatedFromTemplateEvent($templateName === null ? null : $this->file($templateName), $target, []));
	}

	/** A removal that fails is named in the log, and the refusal goes on. */
	public function testNamesTheBlankFileWhoseRemovalFails(): void {
		$target = $this->file('Notes.pad');
		$target->method('delete')->willThrowException(new \RuntimeException('storage gone'));
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willThrowException(new PadTypeDisabledException());
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged[$message] = [$context['app'] ?? null, $context['fileId'] ?? null];
		});

		try {
			$this->buildListener($this->createMock(PadCreationService::class), $bootstrap, logger: $logger)
				->handle(new FileCreatedFromTemplateEvent(null, $target, []));
			$this->fail('the refusal goes on');
		} catch (PadTypeDisabledException) {
			// The refusal, for Nextcloud to report.
		}
		$this->assertSame(['etherpad_nextcloud', 42], $logged['Could not remove the .pad file of a failed create.'] ?? null);
	}

	/**
	 * A blank file the listener cannot initialise is left to the viewer,
	 * which initialises it on its first open: the create succeeds, and a
	 * warning names the file.
	 */
	public function testLeavesABlankFileItCannotInitialiseToTheViewer(): void {
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())->method('initializeMissingFrontmatter')->willThrowException(new \RuntimeException('Etherpad down'));
		$target = $this->untouchedTarget('New pad.pad');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Could not initialise frontmatter for blank-template .pad — falling back to viewer init-retry path.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === 42),
		);

		$this->buildListener($this->createMock(PadCreationService::class), $bootstrap, logger: $logger)
			->handle(new FileCreatedFromTemplateEvent(null, $target, []));
	}

	/**
	 * A create for a user the app is not enabled for provisions nothing -
	 * no pad, no binding, no link to another server - whichever template it
	 * came from, and leaves the file Nextcloud made as it was given.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public static function createsOfEveryKind(): iterable {
		yield 'the blank entry' => ['blank'];
		yield 'the external tile' => ['external'];
		yield 'the tile of a pad type' => ['type tile'];
		yield "the user's own template" => ['own template'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('createsOfEveryKind')]
	public function testProvisionsNothingForAUserTheAppIsNotEnabledFor(string $kind): void {
		$creation = $this->createMock(PadCreationService::class);
		$creation->expects($this->never())->method($this->anything());
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method($this->anything());
		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->expects($this->never())->method($this->anything());
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn($kind === 'external');
		$storage->method('accessModeForTemplateFile')->willReturn($kind === 'type tile' ? 'public' : null);
		$target = $this->untouchedTarget('New pad.pad');
		$template = $kind === 'blank' ? null : $this->file('Template.pad');

		$this->expectException(NotPermittedException::class);
		$this->buildListener($creation, $bootstrap, true, $storage, $seeder, false)
			->handle(new FileCreatedFromTemplateEvent($template, $target, [
				'pad_url' => ['content' => 'https://pad.remote.test/p/RemotePad'],
			]));
	}

	/** A storage that finds $mode for every template (null: none is one of ours) and no external tile. */
	private function tilesOffering(?string $mode): PadTemplateStorage {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('accessModeForTemplateFile')->willReturn($mode);
		$storage->method('isExternalMarkerFile')->willReturn(false);
		return $storage;
	}

	/** The setting changed while the picker was open: the claimed file goes to the writer to remove, and the refusal goes on. */
	public function testRemovesTheFileWhenNoPadTypeIsEnabledAnyMore(): void {
		$template = $this->file('Meeting notes.pad');
		$target = $this->untouchedTarget('Notes.pad');

		$creation = $this->createMock(PadCreationService::class);
		$claim = $this->expectClaimFor($creation, $target);
		$this->writer->expects($this->once())->method('deleteIfUnchanged')->with($this->identicalTo($claim));
		$creation->method('materializeTemplateInto')->willThrowException(new PadTypeDisabledException());
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Pad template create refused: no pad type is enabled.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === 4242),
		);

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($creation, $bootstrap, logger: $logger)
			->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	/** A remote error must not bypass the content check before blank recovery. */
	public function testDoesNotBlankAFileThatIsNoLongerTheClaimedOne(): void {
		$target = $this->untouchedTarget('Notes.pad');

		$claim = new CreatedFileClaim('alice', 4242);
		$creation = $this->createMock(PadCreationService::class);
		$creation->method('claimTemplateTarget')->willReturn($claim);
		// The claimed file is no longer accessible or its content changed.
		$this->writer->expects($this->once())->method('resolveUnchanged')->with($this->identicalTo($claim))->willReturn(null);
		$creation->method('materializeTemplateInto')
			->willThrowException(new \RuntimeException('etherpad unreachable'));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)
			->handle(new FileCreatedFromTemplateEvent($this->file('Template.pad'), $target, []));
	}

	/**
	 * A create from the external tile. It provisions no local pad, which
	 * would quietly make it an internal one, and materializes no template.
	 *
	 * @param array<string,mixed> $fields
	 */
	private function createFromTheExternalTile(PadCreationService&\PHPUnit\Framework\MockObject\MockObject $creation, ExternalPadSeeder $seeder, File $target, array $fields = self::AN_ADDRESS, ?LoggerInterface $logger = null): void {
		$creation->expects($this->never())->method('materializeTemplateInto');
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method($this->anything());
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);

		$this->buildListener($creation, $bootstrap, true, $storage, $seeder, logger: $logger)
			->handle(new FileCreatedFromTemplateEvent($this->file(PadTemplateStorage::EXTERNAL_TILE_NAME), $target, $fields));
	}

	/**
	 * What ExternalPadSeeder::prepare() gives for the remote pad.
	 *
	 * @param array<string,string> $warning
	 * @return array{content:string,result:array<string,mixed>}
	 */
	private function prepared(array $warning = []): array {
		return ['content' => 'the document', 'result' => [
			'file_id' => 4242,
			'pad_id' => 'ext.RemotePad',
			'access_mode' => 'public',
			'pad_url' => 'https://pad.remote.test/p/RemotePad',
		] + $warning];
	}

	/**
	 * The event's $target claimed as file 4242. With $byId, the file is
	 * still as the claim left it and its id finds $byId - another node, as
	 * the file found by its id need not be the one the event remembers by
	 * path.
	 */
	private function expectClaimFor(PadCreationService $creation, File $target, ?File $byId = null): CreatedFileClaim {
		$claim = new CreatedFileClaim('alice', 4242);
		$creation->method('claimTemplateTarget')->with($this->identicalTo($target), 'alice')->willReturn($claim);
		if ($byId !== null) {
			$this->writer->method('resolveUnchanged')->with($claim)->willReturn($byId);
		}

		return $claim;
	}

	/** The node the event hands over, which nothing may write or remove once the file is claimed. */
	private function untouchedTarget(string $name): File {
		$target = $this->file($name);
		$target->expects($this->never())->method('putContent');
		$target->expects($this->never())->method('delete');
		return $target;
	}

	private function buildListener(
		PadCreationService $creation,
		PadBootstrapService $bootstrap,
		bool $withUser = true,
		?PadTemplateStorage $templateStorage = null,
		?ExternalPadSeeder $externalPadSeeder = null,
		bool $appEnabledForUser = true,
		?LoggerInterface $logger = null,
	): FileCreatedFromTemplateListener {
		$session = $this->userSession($withUser);
		return new FileCreatedFromTemplateListener(
			$creation,
			$bootstrap,
			$templateStorage ?? $this->tilesOffering(null),
			$externalPadSeeder ?? $this->createMock(ExternalPadSeeder::class),
			$session,
			$logger ?? $this->createMock(LoggerInterface::class),
			$this->appAccess($appEnabledForUser, $session),
			$this->writer,
		);
	}

	private function userSession(bool $withUser): IUserSession {
		$userSession = $this->createMock(IUserSession::class);
		if ($withUser) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$user->method('getDisplayName')->willReturn('Alice');
			$userSession->method('getUser')->willReturn($user);
			return $userSession;
		}
		$userSession->method('getUser')->willReturn(null);
		return $userSession;
	}

	/** @return File&\PHPUnit\Framework\MockObject\MockObject */
	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(42);
		return $file;
	}

	public function testAChangedTargetIsLeftAloneInsteadOfBlanked(): void {
		$target = $this->untouchedTarget('Notes.pad');
		// Not even for its id, which the claim holds.
		$target->expects($this->never())->method('getId');

		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $target);
		$creation->method('materializeTemplateInto')
			->willThrowException(new PadFileChangedException('changed'));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Pad template create stopped: the target file changed while the pad was provisioned.',
			$this->callback(static fn (array $context): bool => [$context['app'], $context['fileId']] === ['etherpad_nextcloud', 4242]),
		);

		$this->buildListener($creation, $bootstrap, logger: $logger)->handle(new FileCreatedFromTemplateEvent(
			$this->file('Template.pad'),
			$target,
			[],
		));
	}
}
