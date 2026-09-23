<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OC\User\NoUserException;
use PHPUnit\Framework\TestCase;

class UserNodeResolverTest extends TestCase {
	/**
	 * The nodes are served from the *user's* folder, not the global root:
	 * that is where the resolver looks, because getUserFolder() is what
	 * sets the user's mounts up before an id is looked up. A root that
	 * still answered getById() would let a regression back through.
	 */
	private function resolverFor(array $nodes): UserNodeResolver {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn($nodes);
		$userFolder->method('getId')->willReturn(1);
		$rootFolder = $this->createMock(IRootFolder::class);
		// with('alice'): scoping the lookup to *this* user's folder is the
		// property the change rests on, and a stub matching any argument
		// would accept a hardcoded uid just as happily.
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$rootFolder->method('getById')->willThrowException(
			new \LogicException('An id must be resolved through the user folder, not the global root.')
		);
		return new UserNodeResolver($rootFolder);
	}

	/**
	 * Matched with is_a(), not by exact class: nothing marks NoUserException
	 * final, and a subclass means the same thing.
	 */
	public function testTranslatesASubclassOfNoUserException(): void {
		$thrown = new class ('gone') extends NoUserException {
		};
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willThrowException($thrown);
		$resolver = new UserNodeResolver($rootFolder);

		$this->expectException(NotFoundException::class);
		$resolver->resolveUserFileNodeById('alice', 138);
	}

	/**
	 * The other half of the rule: a storage that is down is not a missing
	 * file. Translating it would answer 404 for an outage, with nothing in
	 * the log to say otherwise.
	 */
	public function testLeavesAnUnavailableStorageAlone(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willThrowException(new StorageNotAvailableException('down'));
		$resolver = new UserNodeResolver($rootFolder);

		$this->expectException(StorageNotAvailableException::class);
		$resolver->resolveUserFileNodeById('alice', 138);
	}

	public function testTranslatesARefusedPathIntoNotFound(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willThrowException(new NotPermittedException('denied'));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$resolver = new UserNodeResolver($rootFolder);

		$this->expectException(NotFoundException::class);
		$resolver->resolveUserFileNodeByPath('alice', '/Notes.pad');
	}

	/**
	 * Both restore paths rely on this to keep a folder away from
	 * handleRestore, which takes a File: a folder that got through would
	 * be a TypeError, and on the event path that aborts the restore.
	 */
	public function testAFolderAtTheRequestedPathIsNotAFile(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($this->createMock(Folder::class));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$resolver = new UserNodeResolver($rootFolder);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Path does not reference a file.');
		$resolver->resolveUserFileNodeByPath('alice', '/Projects.pad');
	}

	/** @return iterable<string, array{string, ?array{0: string, 1: string}}> */
	public static function userFilesPathProvider(): iterable {
		yield 'a file below the root' => ['/alice/files/Notes.pad', ['alice', 'Notes.pad']];
		yield 'nested, and a folder named files' => ['/alice/files/files/Notes.pad', ['alice', 'files/Notes.pad']];
		yield 'no leading slash' => ['alice/files/Notes.pad', ['alice', 'Notes.pad']];
		yield 'not under files' => ['/alice/files_trashbin/Notes.pad', null];
		yield 'the files root itself' => ['/alice/files/', null];
		yield 'no owner' => ['//files/Notes.pad', null];
		yield 'too short' => ['/alice/files', null];
	}

	/**
	 * The shape both restore paths read a user's file from. A folder named
	 * `files` below the root is only a folder: the one segment that means
	 * anything is the second.
	 *
	 * @param array{0: string, 1: string}|null $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('userFilesPathProvider')]
	public function testSplitsAUserFilesPathIntoOwnerAndRest(string $path, ?array $expected): void {
		$this->assertSame($expected, UserNodeResolver::splitUserFilesPath($path));
	}

	/**
	 * getUserFolder() answers with its own exception types, and callers of
	 * this class catch NotFoundException to degrade gracefully. Letting
	 * either escape turns an unavailable home storage into a 500.
	 */
	public function testTranslatesAnUnavailableUserFolderIntoNotFound(): void {
		foreach ([new NoUserException('gone'), new NotPermittedException('denied')] as $thrown) {
			$rootFolder = $this->createMock(IRootFolder::class);
			$rootFolder->method('getUserFolder')->willThrowException($thrown);
			$resolver = new UserNodeResolver($rootFolder);

			try {
				$resolver->resolveUserFileNodeById('alice', 138);
				$this->fail('Expected NotFoundException for ' . $thrown::class);
			} catch (NotFoundException $e) {
				$this->assertSame($thrown, $e->getPrevious());
			}
		}
	}

	/**
	 * The user's own root is the one folder that cannot be found by asking
	 * it for its own id, so it is answered directly. Without this,
	 * create-by-parent could not put a pad in "All files".
	 */
	public function testResolvesTheUsersOwnRootFolderById(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getId')->willReturn(7);
		$userFolder->method('getById')->willReturn([]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$resolver = new UserNodeResolver($rootFolder);

		$this->assertSame($userFolder, $resolver->resolveUserFolderNodeById('alice', 7));
	}

	/**
	 * One file, two paths, different permissions — shared to this user
	 * directly and again inside a shared folder. Whichever the lookup
	 * happens to return first decides what the whole open does, including
	 * whether the pad is editable, so it must not be a coin toss.
	 */
	public function testPrefersAPathTheUserMayWrite(): void {
		$readOnly = $this->fileAt('/alice/files/Shared/Notes.pad', updateable: false);
		$writable = $this->fileAt('/alice/files/Team/Notes.pad', updateable: true);

		$this->assertSame(
			$writable,
			$this->resolverFor([$readOnly, $writable])->resolveUserFileNodeById('alice', 42),
			'the read-only mount came first and must not win',
		);
	}

	/** With nothing writable there is still a file to open, read-only. */
	public function testFallsBackToAReadOnlyPathWhenThatIsAllThereIs(): void {
		$readOnly = $this->fileAt('/alice/files/Shared/Notes.pad', updateable: false);

		$this->assertSame(
			$readOnly,
			$this->resolverFor([$readOnly])->resolveUserFileNodeById('alice', 42),
		);
	}

	/** Another user's mount is not an answer, writable or not. */
	public function testStillRejectsAPathBelongingToSomebodyElse(): void {
		$resolver = $this->resolverFor([$this->fileAt('/bob/files/Notes.pad', updateable: true)]);

		$this->expectException(NotFoundException::class);
		$resolver->resolveUserFileNodeById('alice', 42);
	}

	private function fileAt(string $path, bool $updateable): File {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($path);
		$file->method('isUpdateable')->willReturn($updateable);
		return $file;
	}

	private function folderAt(string $path): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn($path);
		return $folder;
	}

	public function testResolvesAFolderBelowTheUserRoot(): void {
		$folder = $this->folderAt('/alice/files/Projects');
		$resolver = $this->resolverFor([$folder]);

		$this->assertSame($folder, $resolver->resolveUserFolderNodeById('alice', 42));
	}

	public function testRejectsAFolderBelongingToAnotherUser(): void {
		$resolver = $this->resolverFor([$this->folderAt('/bob/files/Projects')]);

		$this->expectException(NotFoundException::class);
		$resolver->resolveUserFolderNodeById('alice', 42);
	}

	/** A sibling directory that merely starts the same way is not the root. */
	public function testRejectsALookalikeSiblingOfTheUserRoot(): void {
		$resolver = $this->resolverFor([$this->folderAt('/alice/files_versions/Projects')]);

		$this->expectException(NotFoundException::class);
		$resolver->resolveUserFolderNodeById('alice', 42);
	}

	public function testRejectsAFileWhenAFolderWasAskedFor(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/notes.pad');
		$resolver = $this->resolverFor([$file]);

		$this->expectException(NotFoundException::class);
		$resolver->resolveUserFolderNodeById('alice', 42);
	}

	public function testResolvesAFileBelowTheUserRoot(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/notes.pad');
		$resolver = $this->resolverFor([$file]);

		$this->assertSame($file, $resolver->resolveUserFileNodeById('alice', 42));
	}

	public function testRejectsAnotherUsersFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/bob/files/notes.pad');
		$resolver = $this->resolverFor([$file]);

		$this->expectException(NotFoundException::class);
		$resolver->resolveUserFileNodeById('alice', 42);
	}

	/** Prefer the creatable share regardless of lookup order. */
	#[\PHPUnit\Framework\Attributes\DataProvider('shareOrders')]
	public function testPrefersAFolderThisUserMayCreateIn(bool $writableFirst): void {
		$readOnly = $this->createMock(Folder::class);
		$readOnly->method('getPath')->willReturn('/alice/files/Shared/Team');
		$readOnly->method('isCreatable')->willReturn(false);

		$writable = $this->createMock(Folder::class);
		$writable->method('getPath')->willReturn('/alice/files/Team');
		$writable->method('isCreatable')->willReturn(true);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getId')->willReturn(1);
		$userFolder->method('getById')->willReturn($writableFirst ? [$writable, $readOnly] : [$readOnly, $writable]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$resolver = new UserNodeResolver($rootFolder);

		$this->assertSame($writable, $resolver->resolveUserFolderNodeById('alice', 42));
	}

	/** @return array<string,array{0:bool}> */
	public static function shareOrders(): array {
		return ['writable first' => [true], 'read-only first' => [false]];
	}

	/**
	 * A node found by id earlier has moved when the id no longer resolves
	 * to its path: restored from the trash since, or gone. Asked of the
	 * global root, where a sweep found it.
	 */
	public function testANodeHasMovedWhenItsIdNoLongerResolvesToItsPath(): void {
		$trashed = $this->createMock(File::class);
		$trashed->method('getId')->willReturn(42);
		$trashed->method('getPath')->willReturn('/alice/files_trashbin/files/Notes.pad.d100');
		foreach ([
			'still there' => [['/bob/files/Shared/Notes.pad', '/alice/files_trashbin/files/Notes.pad.d100'], false],
			'restored' => [['/alice/files/Notes.pad'], true],
			'gone' => [[], true],
		] as $case => [$paths, $moved]) {
			$rootFolder = $this->createMock(IRootFolder::class);
			$rootFolder->method('getById')->with(42)->willReturn(array_map(function (string $path): File {
				$node = $this->createMock(File::class);
				$node->method('getPath')->willReturn($path);
				return $node;
			}, $paths));

			$this->assertSame($moved, (new UserNodeResolver($rootFolder))->hasMoved($trashed), $case);
		}
	}
}
