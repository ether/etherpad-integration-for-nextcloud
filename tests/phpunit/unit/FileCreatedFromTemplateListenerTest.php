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
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Tests\Support\BuildsAppAccess;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\ReadsAsATrace;
use OCP\IConfig;
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
	use ReadsAsATrace;
	use BuildsAppAccess;

	private const AN_ADDRESS = ['pad_url' => ['content' => 'https://pad.remote.test/p/RemotePad']];

	/** Writes, finds and removes the claimed target for the listener. */
	private ClaimedFileWriter $writer;

	protected function setUp(): void {
		$this->writer = $this->createMock(ClaimedFileWriter::class);
	}

	/**
	 * A tile create that fails goes to Nextcloud, which logs the exception
	 * with every frame's arguments where PHP keeps them. The address of a
	 * pad on another server opens it to whoever reads that log, so no frame
	 * may show it - the pad's name and the token in its query included.
	 */
	public function testAFailedExternalTileLeavesThePadsAddressOutOfTheTrace(): void {
		$ignoreArgs = ini_set('zend.exception_ignore_args', '0');
		try {
			$config = $this->createMock(IConfig::class);
			$config->method('getAppValue')->willReturnCallback(
				static fn (string $app, string $key, string $default = ''): string => $key === 'allow_external_pads' ? 'yes' : $default,
			);
			$fetcher = new class($config, new FixedClock()) extends ExternalPadExportFetcher {
				protected function lookUp(string $host): array {
					return [['type' => 'A', 'ip' => '1.1.1.1']];
				}

				protected function perform(string $url, array $options): array {
					return ['completed' => false, 'status' => 0, 'error' => 'Connection refused', 'errno' => 7];
				}
			};
			$target = $this->untouchedTarget('Team pad.pad');
			$creation = $this->createMock(PadCreationService::class);
			$this->expectClaimFor($creation, $target);

			try {
				$this->createFromTheExternalTile(
					$creation,
					new ExternalPadSeeder($this->createMock(PadFileService::class), $fetcher),
					$target,
					// Padded, as a picker may hand it over: the trimmed address is
					// then another value than the field it came from.
					['pad_url' => ['content' => ' https://pad.remote.test/p/SecretPad?token=s3cret ']],
				);
				$this->fail('the failed fetch goes on');
			} catch (\OCA\EtherpadNextcloud\Exception\ExternalPadException $e) {
				$logged = (string)json_encode(self::asNextcloudLogs($e), JSON_THROW_ON_ERROR);
			}
		} finally {
			ini_set('zend.exception_ignore_args', (string)$ignoreArgs);
		}

		// The event shows, so a trace without arguments cannot pass for one
		// without the address.
		$this->assertStringContainsString('FileCreatedFromTemplateEvent', $logged);
		$this->assertStringNotContainsString('SecretPad', $logged);
		$this->assertStringNotContainsString('s3cret', $logged);
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
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Template event fired without an active user — resetting target to empty.',
			['app' => 'etherpad_nextcloud', 'fileId' => 42],
		);

		$listener = $this->buildListener($creation, $bootstrap, withUser: false, logger: $logger);
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

	/** The removal is handed to the writer, with a claim on the file's id that expects it empty. */
	#[\PHPUnit\Framework\Attributes\DataProvider('createsThatGoStraightToInitialisation')]
	public function testRemovesTheFileWhenNoPadTypeIsEnabled(?string $templateName, ?string $mode): void {
		$target = $this->file('Notes.pad');
		$target->expects($this->never())->method('delete');
		$this->writer->expects($this->once())->method('deleteIfUnchanged')->with($this->callback(
			static fn (CreatedFileClaim $claim): bool => [$claim->uid, $claim->fileId, $claim->writtenHash] === ['alice', 42, null] && $claim->holds('') && !$claim->holds('notes'),
		));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willThrowException(new PadTypeDisabledException());

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($this->createMock(PadCreationService::class), $bootstrap, true, $this->tilesOffering($mode))
			->handle(new FileCreatedFromTemplateEvent($templateName === null ? null : $this->file($templateName), $target, []));
	}

	/** Without its id nothing tells the file from one written meanwhile: it stays, and the refusal goes on. */
	public function testLeavesABlankFileWhoseIdCannotBeRead(): void {
		$target = $this->createMock(File::class);
		$target->method('getName')->willReturn('Notes.pad');
		$target->method('getId')->willThrowException(new \OCP\Files\NotFoundException('gone'));
		$target->expects($this->never())->method('delete');
		$this->writer->expects($this->never())->method('deleteIfUnchanged');
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willThrowException(new PadTypeDisabledException());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Blank pad create refused: no pad type is enabled.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === null),
		);

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($this->createMock(PadCreationService::class), $bootstrap, logger: $logger)
			->handle(new FileCreatedFromTemplateEvent(null, $target, []));
	}

	/**
	 * A template's file the fallback could not empty is not initialised:
	 * the init expects an empty file, and on the copy it would make a pad
	 * and a row, then be refused the write.
	 */
	public function testDoesNotInitialiseATemplatesFileItCouldNotEmpty(): void {
		$target = $this->untouchedTarget('New.pad');
		$byId = $this->file('New.pad');
		$byId->expects($this->once())->method('putContent')->with('')->willThrowException(new \OCP\Lock\LockedException('New.pad'));
		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $target, $byId);
		$creation->method('materializeTemplateInto')->willThrowException(new \RuntimeException('etherpad unreachable'));
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Could not reset target file content after rejected template.',
			$this->callback(static fn (array $context): bool => [$context['app'], $context['fileId'], $context['error']] === ['etherpad_nextcloud', 4242, \OCP\Lock\LockedException::class]),
		);

		$this->buildListener($creation, $bootstrap, logger: $logger)->handle(new FileCreatedFromTemplateEvent($this->file('Tpl.pad'), $target, []));
	}

	/**
	 * After a reset that returned, a fallback that finds no pad type hands
	 * the removal to the writer, with a claim on the file's id that expects
	 * it empty.
	 */
	public function testRemovesATemplatesEmptiedFileWhenNoPadTypeIsEnabledAnyMore(): void {
		$target = $this->untouchedTarget('New.pad');
		$byId = $this->file('New.pad');
		$byId->expects($this->once())->method('putContent')->with('');
		$creation = $this->createMock(PadCreationService::class);
		$this->expectClaimFor($creation, $target, $byId);
		$creation->method('materializeTemplateInto')->willThrowException(new \RuntimeException('etherpad unreachable'));
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('initializeMissingFrontmatter')->willThrowException(new PadTypeDisabledException());
		$this->writer->expects($this->once())->method('deleteIfUnchanged')->with($this->callback(
			static fn (CreatedFileClaim $claim): bool => [$claim->uid, $claim->fileId, $claim->writtenHash] === ['alice', 4242, null] && $claim->holds(''),
		));

		$this->expectException(PadTypeDisabledException::class);
		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent($this->file('Tpl.pad'), $target, []));
	}

	/** Without a user and without a readable id, the node handed over is left as it is. */
	public function testEmptiesNothingWithoutAUserWhenTheIdCannotBeRead(): void {
		$target = $this->createMock(File::class);
		$target->method('getName')->willReturn('New.pad');
		$target->method('getId')->willThrowException(new \OCP\Files\NotFoundException('gone'));
		$target->expects($this->never())->method('putContent');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Template event fired without an active user; left the target as it is, as its id cannot be read.',
			['app' => 'etherpad_nextcloud'],
		);

		$this->buildListener($this->createMock(PadCreationService::class), $this->createMock(PadBootstrapService::class), withUser: false, logger: $logger)
			->handle(new FileCreatedFromTemplateEvent($this->file('Tpl.pad'), $target, []));
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
