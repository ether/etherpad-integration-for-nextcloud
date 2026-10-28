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

	/** Where the listener asks whether its target is still as the claim left it. */
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

	public function testBlankTemplateInitialisesFrontmatterImmediately(): void {
		// + → New pad with the "Blank" option: NC creates an empty .pad
		// and dispatches the event without a source template. Without this
		// branch the viewer's first /open hits a 400 (no YAML frontmatter)
		// before the retry path runs /initialize. Initialising in the
		// listener removes that first 400.
		$target = $this->file('New.pad');

		$creation = $this->createMock(PadCreationService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $target, '');

		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent(
			null,
			$target,
			[],
		));
	}

	public function testSourceTemplateDelegatesToCreationService(): void {
		$template = $this->file('Tpl.pad');
		$target = $this->file('New.pad');
		$target->expects($this->never())->method('putContent');

		$creation = $this->createMock(PadCreationService::class);
		$creation->expects($this->once())
			->method('materializeTemplateInto')
			->with($template, $this->isInstanceOf(IUser::class));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	public function testFailedSourceTemplateFallsBackToBlankInit(): void {
		// If materializeTemplateInto throws, the byte-copy is wiped and then
		// re-initialised, so the user still gets an openable blank pad rather
		// than one whose first open fails.
		$template = $this->file('Tpl.pad');
		$target = $this->untouchedTarget('New.pad');
		$byId = $this->file('New.pad');
		$byId->expects($this->once())->method('putContent')->with('');

		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $byId);
		$creation->method('materializeTemplateInto')
			->willThrowException(new \RuntimeException('boom'));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $this->identicalTo($byId), '');

		$this->buildListener($creation, $bootstrap)
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
	 * Our own tiles carry no content: they exist so the picker can offer a
	 * pad type. Copying the empty marker over the new pad would leave it
	 * unopenable, so the listener initialises straight to that type.
	 */
	public function testInitialisesToThePadTypeOfOurOwnTemplate(): void {
		$target = $this->file('note.pad');
		$marker = $this->file('Public pad.pad');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('accessModeForTemplateFile')->with($marker)->willReturn('public');

		$creation = $this->createMock(PadCreationService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $target, '', 'public');

		$this->buildListener($creation, $bootstrap, true, $storage)
			->handle(new FileCreatedFromTemplateEvent($marker, $target, []));
	}

	/**
	 * The picker collects the pad's address as a template field, so the file is
	 * linked as it is created — no half-finished file is ever stored.
	 */
	public function testSeedsAFileFromTheExternalTileWithTheAddressThePickerAsked(): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$target = $this->file('Team pad.pad');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);

		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->expects($this->once())
			->method('prepare')
			->with(4242, 'https://pad.remote.test/p/RemotePad')
			->willReturn(['content' => 'the document', 'result' => [
				'file_id' => 42,
				'pad_id' => 'ext.RemotePad',
				'access_mode' => 'public',
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
			]]);

		$creation = $this->createMock(PadCreationService::class);
		$claim = $this->expectClaimFor($creation, $this->file('Team pad.pad'));
		$this->writer->expects($this->once())->method('write')->with($claim, 'the document');
		$creation->expects($this->never())->method('materializeTemplateInto');
		$bootstrap = $this->createMock(PadBootstrapService::class);
		// Provisioning a local pad here would quietly make it an internal one.
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap, true, $storage, $seeder)->handle(
			new FileCreatedFromTemplateEvent($marker, $target, [
				'pad_url' => ['content' => ' https://pad.remote.test/p/RemotePad '],
			])
		);
	}

	/**
	 * A public pad's URL is its access link, and the pad id in it is enough to
	 * open the pad — so the record of an unavailable export names the host and
	 * the file, nothing that hands the pad to whoever reads the log.
	 */
	public function testKeepsThePadOutOfTheLogWhenTheRemoteExportIsUnavailable(): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$target = $this->file('Team pad.pad');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);

		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->method('prepare')->willReturn(['content' => 'the document', 'result' => [
			'file_id' => 42,
			'pad_id' => 'ext.RemotePad',
			'access_mode' => 'public',
			'pad_url' => 'https://pad.remote.test/p/RemotePad',
			'snapshot_warning_code' => 'remote_export_unavailable',
		]]);
		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $this->file('Team pad.pad'));

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = $context;
			}
		);

		$listener = new FileCreatedFromTemplateListener(
			$creation,
			$this->createMock(PadBootstrapService::class),
			$storage,
			$seeder,
			$this->userSession(true),
			$logger,
			$this->appAccess(true),
			$this->writer,
		);
		$listener->handle(new FileCreatedFromTemplateEvent($marker, $target, [
			'pad_url' => ['content' => 'https://pad.remote.test/p/RemotePad?token=s3cret#top'],
		]));

		$this->assertCount(1, $logged);
		$this->assertSame('etherpad_nextcloud', $logged[0]['app'] ?? null);
		$this->assertSame('pad.remote.test', $logged[0]['padHost'] ?? null);
		$this->assertSame(4242, $logged[0]['fileId'] ?? null);
		$this->assertStringNotContainsString('RemotePad', json_encode($logged[0]));
		$this->assertStringNotContainsString('s3cret', json_encode($logged[0]));
	}

	/** @return iterable<string,array{0:array<string,mixed>}> */
	public static function unusableAddressProvider(): iterable {
		yield 'field missing' => [[]];
		yield 'field empty' => [['pad_url' => ['content' => '   ']]];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unusableAddressProvider')]
	public function testRemovesTheFileWhenNoUsableAddressWasGiven(array $fields): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$target = $this->untouchedTarget('Team pad.pad');
		$byId = $this->file('Team pad.pad');
		$byId->expects($this->once())->method('delete');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);

		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->expects($this->never())->method('prepare');
		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $byId);

		$this->expectException(\RuntimeException::class);
		$this->buildListener($creation, $this->createMock(PadBootstrapService::class), true, $storage, $seeder)
			->handle(new FileCreatedFromTemplateEvent($marker, $target, $fields));
	}

	/** A rejected URL must not leave a file behind either. */
	public function testRemovesTheFileWhenTheAddressCannotBeSeeded(): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$target = $this->untouchedTarget('Team pad.pad');
		$byId = $this->file('Team pad.pad');
		$byId->expects($this->once())->method('delete');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);

		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->method('prepare')->willThrowException(new \RuntimeException('pad not reachable'));
		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $byId);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Could not link a new .pad file to an external pad.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === 4242),
		);

		$this->expectExceptionMessage('pad not reachable');
		$this->buildListener($creation, $this->createMock(PadBootstrapService::class), true, $storage, $seeder, logger: $logger)
			->handle(new FileCreatedFromTemplateEvent($marker, $target, ['pad_url' => ['content' => 'https://pad.remote.test/p/x']]));
	}

	public function testLeavesAFileWrittenWhileTheExternalPadWasFetched(): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$target = $this->file('Team pad.pad');
		$target->expects($this->never())->method('delete');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);

		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->method('prepare')->willReturn(['content' => 'the document', 'result' => ['file_id' => 42, 'pad_id' => 'ext.x', 'access_mode' => 'public', 'pad_url' => 'https://pad.remote.test/p/x']]);
		$creation = $this->createMock(PadCreationService::class);
		$creation->method('claimTemplateTarget')->willReturn(new CreatedFileClaim('alice', 42));
		$this->writer->method('write')->willThrowException(new PadFileChangedException('The .pad file changed before its document was written.'));
		$this->writer->method('resolveUnchanged')->willReturn(null);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged[$message] = $context['targetFileId'] ?? $context['fileId'] ?? null;
		});

		try {
			$this->buildListener($creation, $this->createMock(PadBootstrapService::class), true, $storage, $seeder, logger: $logger)
				->handle(new FileCreatedFromTemplateEvent($marker, $target, ['pad_url' => ['content' => 'https://pad.remote.test/p/x']]));
			$this->fail('the refused write goes on');
		} catch (PadFileChangedException) {
			// The refused write goes on, for Nextcloud to report.
		}
		$this->assertSame([
			'Could not link a new .pad file to an external pad.' => 42,
			'Left the template target alone: it changed since this create was given it, or could not be found or read.' => 42,
		], $logged);
	}

	/**
	 * A removal that fails is named with the claimed file's id, not with
	 * whatever the node found by its id answers by then.
	 */
	public function testNamesTheClaimedFileWhenItsRemovalFails(): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$target = $this->untouchedTarget('Team pad.pad');
		$byId = $this->file('Team pad.pad');
		$byId->method('delete')->willThrowException(new \RuntimeException('storage gone'));

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);
		$seeder = $this->createMock(ExternalPadSeeder::class);
		$failure = new \RuntimeException('pad not reachable');
		$seeder->method('prepare')->willThrowException($failure);
		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $byId);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged[$message] = $context['fileId'] ?? null;
		});

		try {
			$this->buildListener($creation, $this->createMock(PadBootstrapService::class), true, $storage, $seeder, logger: $logger)
				->handle(new FileCreatedFromTemplateEvent($marker, $target, ['pad_url' => ['content' => 'https://pad.remote.test/p/x']]));
			$this->fail('the failure goes on');
		} catch (\RuntimeException $e) {
			$this->assertSame($failure, $e);
		}
		$this->assertSame(4242, $logged['Could not remove the .pad file of a failed template create.'] ?? null);
	}

	/** The file's id cannot be read, so the remote pad is not asked either. */
	public function testLeavesTheFileWhenItCannotBeClaimed(): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$target = $this->file('Team pad.pad');
		$target->expects($this->never())->method('delete');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);
		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->expects($this->never())->method('prepare');
		$failure = new \RuntimeException('Could not resolve new file ID.');
		$creation = $this->createMock(PadCreationService::class);
		$creation->method('claimTemplateTarget')->willThrowException($failure);
		$this->writer->expects($this->never())->method('write');
		$this->writer->expects($this->never())->method('resolveUnchanged');

		try {
			$this->buildListener($creation, $this->createMock(PadBootstrapService::class), true, $storage, $seeder)
				->handle(new FileCreatedFromTemplateEvent($marker, $target, ['pad_url' => ['content' => 'https://pad.remote.test/p/x']]));
			$this->fail('the failure goes on');
		} catch (\RuntimeException $e) {
			$this->assertSame($failure, $e);
		}
	}

	public function testRemovesTheBlankFileWhenNoPadTypeIsEnabled(): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->once())->method('delete');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willThrowException(new PadTypeDisabledException());

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($this->createMock(PadCreationService::class), $bootstrap)
			->handle(new FileCreatedFromTemplateEvent(null, $target, []));
	}

	/** A type tile provisions a pad as the blank entry does, so its file goes too. */
	public function testRemovesTheFileOfTheTypeTileWhenNoPadTypeIsEnabled(): void {
		$marker = $this->file(PadTemplateStorage::PUBLIC_TILE_NAME);
		$target = $this->file('Notes.pad');
		$target->expects($this->once())->method('delete');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(false);
		$storage->method('accessModeForTemplateFile')->willReturn('public');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willThrowException(new PadTypeDisabledException('public'));

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($this->createMock(PadCreationService::class), $bootstrap, true, $storage)
			->handle(new FileCreatedFromTemplateEvent($marker, $target, []));
	}

	/**
	 * A blank file the listener cannot initialise is left to the viewer,
	 * which initialises it on its first open: the create succeeds, and a
	 * warning names the file.
	 */
	public function testLeavesABlankFileItCannotInitialiseToTheViewer(): void {
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())->method('initializeMissingFrontmatter')->willThrowException(new \RuntimeException('Etherpad down'));
		$target = $this->file('New pad.pad');
		$target->expects($this->never())->method('delete');
		$target->expects($this->never())->method('putContent');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Could not initialise frontmatter for blank-template .pad — falling back to viewer init-retry path.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === 42),
		);
		$session = $this->userSession(true);
		$listener = new FileCreatedFromTemplateListener(
			$this->createMock(PadCreationService::class),
			$bootstrap,
			$this->noTypeTemplates(),
			$this->createMock(ExternalPadSeeder::class),
			$session,
			$logger,
			$this->appAccess(true, $session),
			$this->writer,
		);

		$listener->handle(new FileCreatedFromTemplateEvent(null, $target, []));
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
		$target = $this->file('New pad.pad');
		$target->expects($this->never())->method('delete');
		$target->expects($this->never())->method('putContent');
		$template = $kind === 'blank' ? null : $this->file('Template.pad');

		$this->expectException(NotPermittedException::class);
		$this->buildListener($creation, $bootstrap, true, $storage, $seeder, false)
			->handle(new FileCreatedFromTemplateEvent($template, $target, [
				'pad_url' => ['content' => 'https://pad.remote.test/p/RemotePad'],
			]));
	}

	/** A storage that recognises no template as one of ours. */
	private function noTypeTemplates(): PadTemplateStorage {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('accessModeForTemplateFile')->willReturn(null);
		$storage->method('isExternalMarkerFile')->willReturn(false);
		return $storage;
	}

	/**
	 * The setting can change while the picker is open. Materialising then fails
	 * correctly — and the blank fallback would fail for the same reason,
	 * leaving an empty .pad nobody can open.
	 */
	public function testRemovesTheFileWhenNoPadTypeIsEnabledAnyMore(): void {
		$template = $this->file('Meeting notes.pad');
		$target = $this->untouchedTarget('Notes.pad');
		$byId = $this->file('Notes.pad');
		$byId->expects($this->once())->method('delete');

		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $byId);
		$creation->method('materializeTemplateInto')->willThrowException(new PadTypeDisabledException());
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($creation, $bootstrap)
			->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	/** A remote error must not bypass the content check before blank recovery. */
	public function testDoesNotBlankAFileThatIsNoLongerTheClaimedOne(): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->never())->method('putContent');
		$target->expects($this->never())->method('delete');

		$creation = $this->createMock(PadCreationService::class);
		$creation->method('claimTemplateTarget')->willReturn(new CreatedFileClaim('alice', 4242));
		// The claimed file is no longer accessible or its content changed.
		$this->writer->method('resolveUnchanged')->willReturn(null);
		$creation->method('materializeTemplateInto')
			->willThrowException(new \RuntimeException('etherpad unreachable'));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Left the template target alone: it changed since this create was given it, or could not be found or read.',
			['app' => 'etherpad_nextcloud', 'targetFileId' => 4242],
		);

		$this->buildListener($creation, $bootstrap, logger: $logger)
			->handle(new FileCreatedFromTemplateEvent($this->file('Template.pad'), $target, []));
	}

	public function testDoesNotDeleteAFileThatIsNoLongerTheClaimedOne(): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->never())->method('delete');

		$creation = $this->createMock(PadCreationService::class);
		$creation->method('claimTemplateTarget')->willReturn(new CreatedFileClaim('alice', 4242));
		$this->writer->method('resolveUnchanged')->willReturn(null);
		$creation->method('materializeTemplateInto')->willThrowException(new PadTypeDisabledException());
		// Left, and said so.
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->willReturnCallback(function (string $message, array $context): void {
			$this->assertSame(['etherpad_nextcloud', 4242], [$context['app'], $context['targetFileId']]);
		});

		$this->expectException(PadTypeDisabledException::class);

		$this->buildListener($creation, $this->createMock(PadBootstrapService::class), logger: $logger)
			->handle(new FileCreatedFromTemplateEvent($this->file('Template.pad'), $target, []));
	}

	/**
	 * A claimed target that remains eligible for recovery: file 4242, whose
	 * id finds $byId - another node than the event's, as the file found by
	 * its id need not be the one the event remembers by path.
	 */
	private function expectClaimFor(PadCreationService $creation, File $byId): CreatedFileClaim {
		$claim = new CreatedFileClaim('alice', 4242);
		$creation->method('claimTemplateTarget')->with($this->anything(), 'alice')->willReturn($claim);
		$this->writer->method('resolveUnchanged')->with($claim)->willReturn($byId);

		return $claim;
	}

	/** The node the event hands over, which nothing may write or remove once the file is claimed. */
	private function untouchedTarget(string $name): File {
		$target = $this->createMock(File::class);
		$target->method('getName')->willReturn($name);
		$target->method('getId')->willReturn(42);
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
			$templateStorage ?? $this->noTypeTemplates(),
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

	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(42);
		return $file;
	}

	public function testAChangedTargetIsLeftAloneInsteadOfBlanked(): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->never())->method('putContent');
		$target->expects($this->never())->method('delete');
		// The handler must not ask the stale node anything, id included: it
		// answers for whatever holds the path by then, and it can throw
		// while an error is already being handled.
		$target->expects($this->never())->method('getId');

		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $this->file('Notes.pad'));
		$creation->method('materializeTemplateInto')
			->willThrowException(new PadFileChangedException('changed'));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent(
			$this->file('Template.pad'),
			$target,
			[],
		));
	}
}
