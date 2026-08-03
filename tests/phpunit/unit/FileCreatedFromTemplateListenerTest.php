<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\FileCreatedFromTemplateListener;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
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
	 * A pasted address may carry a query or fragment, and canonicalisation is
	 * what drops them — so the record of an unavailable export must not repeat
	 * what was typed, or a token would land in the server log.
	 */
	public function testLogsTheCanonicalAddressWhenTheRemoteExportIsUnavailable(): void {
		$marker = $this->file(PadTemplateStorage::EXTERNAL_MARKER);
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
		$this->assertSame('https://pad.remote.test/p/RemotePad', $logged[0]['padUrl'] ?? null);
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
		$marker = $this->file(PadTemplateStorage::PUBLIC_MARKER);
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
		$creation->method('materializeTemplateInto')->willThrowException(new PadTypeDisabledException());
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($creation, $bootstrap)
			->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	/** The same for Nextcloud's blank entry, which provisions just as directly. */
	public function testRemovesTheBlankFileWhenNoPadTypeIsEnabled(): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->once())->method('delete');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willThrowException(new PadTypeDisabledException());

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($this->createMock(PadCreationService::class), $bootstrap)
			->handle(new FileCreatedFromTemplateEvent(null, $target, []));
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
}
