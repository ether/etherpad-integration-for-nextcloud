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
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('acquireLock')
			->with(self::FOLDER_PATH . '/agenda.pad', ILockingProvider::LOCK_EXCLUSIVE);
		$locks->expects($this->once())->method('releaseLock')
			->with(self::FOLDER_PATH . '/agenda.pad', ILockingProvider::LOCK_EXCLUSIVE);

		$folder = $this->folderWith([]);
		$folder->method('newFile')->willReturn($this->namedFile('agenda.pad'));

		$this->buildStorage($folder, $locks)->addGlobalTemplate('agenda.pad', 'content');
	}

	/** Deleting takes the same lock, or it could interleave with an upload. */
	public function testDeletesUnderTheSameLock(): void {
		$file = $this->namedFile('agenda.pad');
		$file->expects($this->once())->method('delete');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('acquireLock')
			->with(self::FOLDER_PATH . '/agenda.pad', ILockingProvider::LOCK_EXCLUSIVE);
		$locks->expects($this->once())->method('releaseLock');

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn(self::FOLDER_PATH);
		$folder->method('getDirectoryListing')->willReturn([$file]);

		$this->assertTrue($this->buildStorage($folder, $locks)->deleteGlobalTemplate('agenda.pad'));
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

	private function namedFile(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		return $file;
	}
}
