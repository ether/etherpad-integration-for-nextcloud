<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\TemplateExistsException;
use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

class PadTemplateStorageTest extends TestCase {
	private const FOLDER_PATH = '/appdata_testinstance/etherpad_nextcloud/templates';
	private const MARKER_PATH = '/appdata_testinstance/etherpad_nextcloud/type_templates';

	public function testReusesAnExistingMarker(): void {
		$marker = $this->namedFile(PadTemplateStorage::PUBLIC_TILE_NAME);
		$folder = $this->folderWith([PadTemplateStorage::PUBLIC_TILE_NAME => $marker]);
		$folder->expects($this->never())->method('newFile');

		$this->assertSame($marker, $this->buildStorage($folder)->publicMarker());
	}

	public function testCreatesTheMarkerWhenItIsMissing(): void {
		$created = $this->namedFile(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$folder = $this->folderWith([]);
		$folder->expects($this->once())
			->method('newFile')
			->with(PadTemplateStorage::EXTERNAL_TILE_NAME, '')
			->willReturn($created);

		$this->assertSame($created, $this->buildStorage($folder)->externalMarker());
	}

	/** Two first requests can try to create the same marker at once. */
	public function testRereadsWhenAnotherRequestCreatedTheMarkerFirst(): void {
		$existing = $this->namedFile(PadTemplateStorage::PUBLIC_TILE_NAME);
		$calls = 0;
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('get')->willReturnCallback(
			function () use (&$calls, $existing): File {
				$calls += 1;
				if ($calls === 1) {
					throw new NotFoundException('not yet');
				}
				return $existing;
			}
		);
		$folder->method('newFile')->willThrowException(new \RuntimeException('already exists'));

		$this->assertSame($existing, $this->buildStorage($folder)->publicMarker());
	}

	/**
	 * Losing a race is not an error; running out of space is. Answering the
	 * second with "not found" would hide what actually went wrong.
	 */
	public function testKeepsTheCreateErrorWhenTheMarkerIsStillMissing(): void {
		$folder = $this->folderWith([]);
		$folder->method('newFile')->willThrowException(new \RuntimeException('no space left'));

		$this->expectExceptionMessage('no space left');
		$this->buildStorage($folder)->publicMarker();
	}

	public function testRecognisesEachMarkerByItsPath(): void {
		$storage = $this->buildStorage($this->folderWith([]));

		$this->assertTrue($storage->isPublicMarkerFile($this->fileAt(self::MARKER_PATH . '/' . PadTemplateStorage::PUBLIC_TILE_NAME)));
		$this->assertTrue($storage->isExternalMarkerFile($this->fileAt(self::MARKER_PATH . '/' . PadTemplateStorage::EXTERNAL_TILE_NAME)));
		$this->assertSame('public', $storage->accessModeForTemplateFile($this->fileAt(self::MARKER_PATH . '/' . PadTemplateStorage::PUBLIC_TILE_NAME)));
	}

	/**
	 * A user's own template may carry the same name. It lives in their files,
	 * not under appdata, so only the path can tell the two apart.
	 */
	public function testDoesNotMistakeAUserFileOfTheSameNameForAMarker(): void {
		$storage = $this->buildStorage($this->folderWith([]));
		$userFile = $this->fileAt('/alice/files/Templates/' . PadTemplateStorage::PUBLIC_TILE_NAME);

		$this->assertFalse($storage->isPublicMarkerFile($userFile));
		$this->assertSame('', $storage->accessModeForTemplateFile($userFile));
	}

	/**
	 * Recognising a marker runs while a file is being created: it must not
	 * write, and a storage that is unavailable must not be able to answer
	 * "not a marker" — the caller would then treat the external tile as an
	 * ordinary template and quietly produce a local pad.
	 */
	public function testRecognisingAMarkerTouchesNoStorageAtAll(): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->never())->method('newFile');
		$folder->expects($this->never())->method('get');
		$folder->expects($this->never())->method('getDirectoryListing');

		$appData = $this->createMock(IAppData::class);
		$appData->expects($this->never())->method('getFolder');
		$appData->expects($this->never())->method('newFolder');

		$storage = $this->buildStorage($folder, null, $appData);

		$this->assertTrue($storage->isExternalMarkerFile($this->fileAt(self::MARKER_PATH . '/' . PadTemplateStorage::EXTERNAL_TILE_NAME)));
		$this->assertFalse($storage->isPublicMarkerFile($this->fileAt('/somewhere/else.pad')));
	}

	/**
	 * An admin template of the same name keeps being a template: the markers
	 * have their own folder, so an upload can never be turned into a tile.
	 */
	public function testDoesNotMistakeASharedTemplateOfTheSameNameForAMarker(): void {
		$storage = $this->buildStorage($this->folderWith([]));

		$this->assertFalse($storage->isPublicMarkerFile($this->fileAt(self::FOLDER_PATH . '/' . PadTemplateStorage::PUBLIC_TILE_NAME)));
	}

	public function testListsUploadedTemplatesByName(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('getDirectoryListing')->willReturn([
			$this->namedFile('Zebra.pad'),
			$this->namedFile('notes.txt'),
			$this->namedFile('agenda.pad'),
			$this->createMock(Folder::class),
		]);

		$names = array_map(
			static fn(File $file): string => $file->getName(),
			$this->buildStorage($folder)->globalTemplates(),
		);

		$this->assertSame(['agenda.pad', 'Zebra.pad'], $names);
	}

	/**
	 * An admin looking at an empty list must be able to tell "none uploaded"
	 * from "appdata unreadable".
	 */
	public function testDoesNotHideAFailingListing(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('getDirectoryListing')->willThrowException(new \RuntimeException('appdata unavailable'));

		$this->expectExceptionMessage('appdata unavailable');
		$this->buildStorage($folder)->globalTemplates();
	}

	/** The name comes from the client, so it is matched, never resolved. */
	public function testFindsATemplateThroughTheListingOnly(): void {
		$wanted = $this->namedFile('agenda.pad');
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('getDirectoryListing')->willReturn([$wanted]);
		$folder->expects($this->never())->method('get');

		$storage = $this->buildStorage($folder);

		$this->assertSame($wanted, $storage->globalTemplate('agenda.pad'));
		$this->assertNull($storage->globalTemplate('../../secrets.pad'));
	}

	public function testStoresANewTemplate(): void {
		$created = $this->namedFile('agenda.pad');
		$folder = $this->folderWith([]);
		$folder->expects($this->once())->method('newFile')->with('agenda.pad', 'content')->willReturn($created);

		$this->assertSame($created, $this->buildStorage($folder)->addGlobalTemplate('agenda.pad', 'content'));
	}

	public function testOverwritesInPlaceWhenAsked(): void {
		$existing = $this->namedFile('agenda.pad');
		$existing->expects($this->once())->method('putContent')->with('new content');
		$folder = $this->folderWith(['agenda.pad' => $existing]);
		$folder->expects($this->never())->method('newFile');

		$this->assertSame($existing, $this->buildStorage($folder)->addGlobalTemplate('agenda.pad', 'new content', true));
	}

	/**
	 * The decision belongs here rather than in a check the caller runs first:
	 * newFile() overwrites what is already there, so a separate look would let
	 * two uploads both find the name free and the second one win silently.
	 */
	public function testRefusesToOverwriteUnlessAsked(): void {
		$existing = $this->namedFile('agenda.pad');
		$existing->expects($this->never())->method('putContent');
		$folder = $this->folderWith(['agenda.pad' => $existing]);

		$this->expectException(TemplateExistsException::class);
		$this->buildStorage($folder)->addGlobalTemplate('agenda.pad', 'new content');
	}

	/** Check and write are one step, so the name is held across both. */
	public function testHoldsAnExclusiveLockWhileWriting(): void {
		$acquired = [];
		$released = [];
		$locks = $this->lockRecorder($acquired, $released);

		$folder = $this->folderWith([]);
		$folder->method('newFile')->willReturn($this->namedFile('agenda.pad'));

		$this->buildStorage($folder, $locks)->addGlobalTemplate('agenda.pad', 'content');

		$this->assertSame($acquired, $released);
		$this->assertCount(1, $acquired);
		[$key, $type] = $acquired[0];
		$this->assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type);
		// Nextcloud stores this key in a 64-character column, so the plain
		// path — already 68 characters with an ordinary instance id — would
		// fail the write on any instance not backed by Redis.
		$this->assertLessThanOrEqual(64, strlen($key));
		$this->assertStringStartsWith('etherpad_nextcloud:', $key);
		$this->assertStringNotContainsString('agenda.pad', $key);
	}

	/** Two names must not be able to lock each other out. */
	public function testLocksEachNameSeparately(): void {
		$acquired = [];
		$released = [];
		$folder = $this->folderWith([]);
		$folder->method('newFile')->willReturn($this->namedFile('x.pad'));

		$storage = $this->buildStorage($folder, $this->lockRecorder($acquired, $released));
		$storage->addGlobalTemplate('agenda.pad', 'content');
		$storage->addGlobalTemplate('minutes.pad', 'content');

		$this->assertNotSame($acquired[0], $acquired[1]);
	}

	/** A long name must not push the key past what the column can hold. */
	public function testKeepsTheLockKeyBoundedForAnyName(): void {
		$acquired = [];
		$released = [];
		$folder = $this->folderWith([]);
		$folder->method('newFile')->willReturn($this->namedFile('x.pad'));

		$this->buildStorage($folder, $this->lockRecorder($acquired, $released))
			->addGlobalTemplate(str_repeat('a', 190) . '.pad', 'content');

		$this->assertLessThanOrEqual(64, strlen($acquired[0][0]));
	}

	/** Deleting takes the same lock as writing, or the two could interleave. */
	public function testDeletesUnderTheSameLockAsWriting(): void {
		$file = $this->namedFile('agenda.pad');
		$file->expects($this->once())->method('delete');
		$released = [];
		$written = [];
		$deleted = [];

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('getDirectoryListing')->willReturn([$file]);
		$folder->method('get')->willReturn($file);

		$this->buildStorage($folder, $this->lockRecorder($written, $released))
			->addGlobalTemplate('agenda.pad', 'content', true);
		$this->assertTrue(
			$this->buildStorage($folder, $this->lockRecorder($deleted, $released))->deleteGlobalTemplate('agenda.pad')
		);

		$this->assertSame($written, $deleted);
	}

	public function testReportsADeleteOfSomethingThatIsNotThere(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('getDirectoryListing')->willReturn([]);

		$this->assertFalse($this->buildStorage($folder)->deleteGlobalTemplate('gone.pad'));
	}

	/** A held name is a concurrent write, which nobody agreed to overwrite. */
	public function testTreatsAHeldLockAsAnExistingTemplate(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new LockedException('busy'));
		$locks->expects($this->never())->method('releaseLock');

		$folder = $this->folderWith([]);
		$folder->expects($this->never())->method('newFile');

		$this->expectException(TemplateExistsException::class);
		$this->buildStorage($folder, $locks)->addGlobalTemplate('agenda.pad', 'content', true);
	}

	/** Otherwise a failed write would leave the name unusable until restart. */
	public function testReleasesTheLockWhenWritingFails(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('releaseLock');

		$folder = $this->folderWith([]);
		$folder->method('newFile')->willThrowException(new \RuntimeException('no space left'));

		$this->expectExceptionMessage('no space left');
		$this->buildStorage($folder, $locks)->addGlobalTemplate('agenda.pad', 'content');
	}

	/**
	 * A permission or storage error must not read as "the name is free": the
	 * write that follows would report the wrong problem.
	 */
	public function testDoesNotTreatAStorageErrorAsAMissingFile(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('get')->willThrowException(new \RuntimeException('permission denied'));
		$folder->expects($this->never())->method('newFile');

		$this->expectExceptionMessage('permission denied');
		$this->buildStorage($folder)->addGlobalTemplate('agenda.pad', 'content');
	}

	/** IAppData owns the folder, including creating it on first use. */
	public function testCreatesTheTemplateFolderOnFirstUse(): void {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new NotFoundException('templates'));
		$appData->expects($this->once())
			->method('newFolder')
			->with(PadTemplateStorage::TEMPLATE_DIR)
			->willReturn($this->createMock(ISimpleFolder::class));

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('getDirectoryListing')->willReturn([]);

		$this->assertSame([], $this->buildStorage($folder, null, $appData)->globalTemplates());
	}

	/** The other outcome of that race: another request created it first. */
	public function testUsesTheFolderAnotherRequestCreatedFirst(): void {
		$calls = 0;
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(
			function () use (&$calls): ISimpleFolder {
				$calls += 1;
				if ($calls === 1) {
					throw new NotFoundException('templates');
				}
				return $this->createMock(ISimpleFolder::class);
			}
		);
		$appData->method('newFolder')->willThrowException(new \RuntimeException('already exists'));

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('getDirectoryListing')->willReturn([$this->namedFile('agenda.pad')]);

		$names = array_map(
			static fn(File $file): string => $file->getName(),
			$this->buildStorage($folder, null, $appData)->globalTemplates(),
		);

		$this->assertSame(['agenda.pad'], $names);
	}

	/**
	 * A failed create is only harmless when the folder is there afterwards.
	 * Answering a permission or quota failure with "not found" would replace
	 * the one message that says what actually happened.
	 */
	public function testKeepsTheFolderCreateErrorWhenItIsStillMissing(): void {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new NotFoundException('templates'));
		$appData->method('newFolder')->willThrowException(new \RuntimeException('permission denied'));

		$this->expectExceptionMessage('permission denied');
		$this->buildStorage($this->folderWith([]), null, $appData)->globalTemplates();
	}

	/**
	 * Records key *and* type: a shared lock would let two writers through, and
	 * a recorder that only kept the key could not tell the difference.
	 *
	 * @param list<array{0:string,1:int}> $acquired
	 * @param list<array{0:string,1:int}> $released
	 */
	private function lockRecorder(array &$acquired, array &$released): ILockingProvider {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(
			static function (string $path, int $type) use (&$acquired): void {
				$acquired[] = [$path, $type];
			}
		);
		$locks->method('releaseLock')->willReturnCallback(
			static function (string $path, int $type) use (&$released): void {
				$released[] = [$path, $type];
			}
		);
		return $locks;
	}

	private function buildStorage(
		Folder $templateFolder,
		?ILockingProvider $lockingProvider = null,
		?IAppData $appData = null,
	): PadTemplateStorage {
		if ($appData === null) {
			$appData = $this->createMock(IAppData::class);
			$appData->method('getFolder')->willReturn($this->createMock(ISimpleFolder::class));
		}
		$appDataFactory = $this->createMock(IAppDataFactory::class);
		$appDataFactory->method('get')->willReturn($appData);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getAppDataDirectoryName')->willReturn('appdata_testinstance');
		$rootFolder->method('get')->willReturn($templateFolder);

		return new PadTemplateStorage(
			$rootFolder,
			$appDataFactory,
			$lockingProvider ?? $this->createMock(ILockingProvider::class),
		);
	}

	/** @param array<string,File> $entries */
	private function folderWith(array $entries): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('getDirectoryListing')->willReturn(array_values($entries));
		$folder->method('get')->willReturnCallback(
			static function (string $name) use ($entries): File {
				if (!isset($entries[$name])) {
					throw new NotFoundException($name);
				}
				return $entries[$name];
			}
		);
		return $folder;
	}

	private function fileAt(string $path): File {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($path);
		return $file;
	}

	private function namedFile(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		return $file;
	}
}
