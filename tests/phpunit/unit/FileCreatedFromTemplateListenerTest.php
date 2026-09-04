<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\FileCreatedFromTemplateListener;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
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
			->with($target, $template, $this->isInstanceOf(IUser::class));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	public function testFailedSourceTemplateFallsBackToBlankInit(): void {
		// If materializeTemplateInto throws, the byte-copy is wiped *and*
		// then re-initialised so the user still ends up with an openable
		// blank pad (not the 4xx-on-first-open state that existed before
		// the fall-through init).
		$template = $this->file('Tpl.pad');
		$target = $this->file('New.pad');
		$target->expects($this->once())->method('putContent')->with('');

		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $target);
		$creation->method('materializeTemplateInto')
			->willThrowException(new \RuntimeException('boom'));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $target, '');

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
			->method('seed')
			->with($target, 42, 'https://pad.remote.test/p/RemotePad')
			->willReturn([
				'file_id' => 42,
				'pad_id' => 'ext.RemotePad',
				'access_mode' => 'public',
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
			]);

		$creation = $this->createMock(PadCreationService::class);
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
		$seeder->method('seed')->willReturn([
			'file_id' => 42,
			'pad_id' => 'ext.RemotePad',
			'access_mode' => 'public',
			'pad_url' => 'https://pad.remote.test/p/RemotePad',
			'snapshot_warning_code' => 'remote_export_unavailable',
		]);

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = $context;
			}
		);

		$listener = new FileCreatedFromTemplateListener(
			$this->createMock(PadCreationService::class),
			$this->createMock(PadBootstrapService::class),
			$storage,
			$seeder,
			$this->userSession(true),
			$logger,
		);
		$listener->handle(new FileCreatedFromTemplateEvent($marker, $target, [
			'pad_url' => ['content' => 'https://pad.remote.test/p/RemotePad?token=s3cret#top'],
		]));

		$this->assertCount(1, $logged);
		$this->assertSame('pad.remote.test', $logged[0]['padHost'] ?? null);
		$this->assertSame(42, $logged[0]['fileId'] ?? null);
		$this->assertStringNotContainsString('RemotePad', json_encode($logged[0]));
		$this->assertStringNotContainsString('s3cret', json_encode($logged[0]));
	}

	/**
	 * An empty `.pad` left behind would become an ordinary local pad on first
	 * open — not what the user picked. Nextcloud turns the exception into its
	 * own "could not create from template" message.
	 *
	 * @return iterable<string,array{0:array<string,mixed>}>
	 */
	public static function unusableAddressProvider(): iterable {
		yield 'field missing' => [[]];
		yield 'field empty' => [['pad_url' => ['content' => '   ']]];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unusableAddressProvider')]
	public function testRemovesTheFileWhenNoUsableAddressWasGiven(array $fields): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$target = $this->file('Team pad.pad');
		$target->expects($this->once())->method('delete');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);

		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->expects($this->never())->method('seed');

		$this->expectException(\RuntimeException::class);
		$this->buildListener($this->createMock(PadCreationService::class), $this->createMock(PadBootstrapService::class), true, $storage, $seeder)
			->handle(new FileCreatedFromTemplateEvent($marker, $target, $fields));
	}

	/** A rejected URL must not leave a file behind either. */
	public function testRemovesTheFileWhenTheAddressCannotBeSeeded(): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$target = $this->file('Team pad.pad');
		$target->expects($this->once())->method('delete');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('isExternalMarkerFile')->willReturn(true);

		$seeder = $this->createMock(ExternalPadSeeder::class);
		$seeder->method('seed')->willThrowException(new \RuntimeException('pad not reachable'));

		$this->expectExceptionMessage('pad not reachable');
		$this->buildListener($this->createMock(PadCreationService::class), $this->createMock(PadBootstrapService::class), true, $storage, $seeder)
			->handle(new FileCreatedFromTemplateEvent($marker, $target, ['pad_url' => ['content' => 'https://pad.remote.test/p/x']]));
	}

	/**
	 * An instance that allows only external pads still shows "New pad" and,
	 * with it, Nextcloud's blank entry. Provisioning then fails — correctly —
	 * and the empty file must not stay behind.
	 */
	public function testRemovesTheBlankFileWhenNoPadTypeIsEnabled(): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->once())->method('delete');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willThrowException(new PadTypeDisabledException());

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($this->createMock(PadCreationService::class), $bootstrap)
			->handle(new FileCreatedFromTemplateEvent(null, $target, []));
	}

	/** The same for the type tile, which provisions a pad just as directly. */
	public function testRemovesTheFileOfTheTypeTileWhenThatTypeIsGone(): void {
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

	/** A storage that recognises no template as one of ours. */
	private function noTypeTemplates(): PadTemplateStorage {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('accessModeForTemplateFile')->willReturn('');
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
		$target = $this->file('Notes.pad');
		$target->expects($this->once())->method('delete');

		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $target);
		$creation->method('materializeTemplateInto')->willThrowException(new PadTypeDisabledException());
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($creation, $bootstrap)
			->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	/**
	 * The general failure branch is not exempt. A pad call can fail
	 * *because* the file moved, and the name it left behind may belong to
	 * somebody else — so the blank recovery only runs on a file that is
	 * still the one this listener was handed.
	 */
	public function testDoesNotBlankAFileThatIsNoLongerTheClaimedOne(): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->never())->method('putContent');
		$target->expects($this->never())->method('delete');

		$creation = $this->createMock(PadCreationService::class);
		$creation->method('claimTemplateTarget')->willReturn(new CreatedFileClaim('alice', 4242));
		// Moved away, or replaced at that path: nothing matches the claim.
		$creation->method('resolveUnchangedClaim')->willReturn(null);
		$creation->method('materializeTemplateInto')
			->willThrowException(new \RuntimeException('etherpad unreachable'));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)
			->handle(new FileCreatedFromTemplateEvent($this->file('Template.pad'), $target, []));
	}

	/** Same rule for the refusal that deletes rather than blanks. */
	public function testDoesNotDeleteAFileThatIsNoLongerTheClaimedOne(): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->never())->method('delete');

		$creation = $this->createMock(PadCreationService::class);
		$creation->method('claimTemplateTarget')->willReturn(new CreatedFileClaim('alice', 4242));
		$creation->method('resolveUnchangedClaim')->willReturn(null);
		$creation->method('materializeTemplateInto')->willThrowException(new PadTypeDisabledException());

		$this->expectException(PadTypeDisabledException::class);

		$this->buildListener($creation, $this->createMock(PadBootstrapService::class))
			->handle(new FileCreatedFromTemplateEvent($this->file('Template.pad'), $target, []));
	}

	/**
	 * The listener claims its target before materialising and measures every
	 * recovery against that claim, so a double that answers neither call
	 * leaves it with nothing to act on.
	 */
	private function expectClaimFor(PadCreationService $creation, File $target, int $fileId = 4242): CreatedFileClaim {
		$claim = new CreatedFileClaim('alice', $fileId);
		$creation->method('claimTemplateTarget')->willReturn($claim);
		$creation->method('resolveUnchangedClaim')->willReturn($target);

		return $claim;
	}

	private function buildListener(
		PadCreationService $creation,
		PadBootstrapService $bootstrap,
		bool $withUser = true,
		?PadTemplateStorage $templateStorage = null,
		?ExternalPadSeeder $externalPadSeeder = null,
	): FileCreatedFromTemplateListener {
		return new FileCreatedFromTemplateListener(
			$creation,
			$bootstrap,
			$templateStorage ?? $this->noTypeTemplates(),
			$externalPadSeeder ?? $this->createMock(ExternalPadSeeder::class),
			$this->userSession($withUser),
			$this->createMock(LoggerInterface::class),
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

	/**
	 * The write refuses because somebody else filled the file while the pad
	 * was being provisioned. The blank fallback would empty exactly that
	 * content, so this failure has to end without touching the file.
	 */
	public function testAChangedTargetIsLeftAloneInsteadOfBlanked(): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->never())->method('putContent');
		$target->expects($this->never())->method('delete');

		$creation = $this->createMock(PadCreationService::class);
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
