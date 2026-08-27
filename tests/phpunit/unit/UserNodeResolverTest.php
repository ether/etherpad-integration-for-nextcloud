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
use PHPUnit\Framework\TestCase;

class UserNodeResolverTest extends TestCase {
	/**
	 * Cleanup identifies its file by id, so this is the guard that decides
	 * what may be deleted.
	 */
	public function testFindsTheUsersOwnFileById(): void {
		$file = $this->ownedFile('/alice/files/notes.pad', 'alice');

		$this->assertSame($file, $this->resolverForFirstNode($file)->findUserFileById('alice', 42));
	}

	/**
	 * A pad created in a folder someone shared with this user belongs, at
	 * storage level, to whoever shared it. Refusing those would refuse to
	 * clean up exactly the creates that happen inside shares — what makes a
	 * file safe to delete is decided on its contents, not its owner.
	 */
	public function testAcceptsAFileInsideAShareTheUserCanReach(): void {
		$shared = $this->ownedFile('/alice/files/Shared/Team/notes.pad', 'bob');

		$this->assertSame($shared, $this->resolverForFirstNode($shared)->findUserFileById('alice', 42));
	}

	public function testRefusesANodeOutsideTheUsersFileTree(): void {
		$outside = $this->ownedFile('/alice/thumbnails/42.pad', 'alice');

		$this->assertNull($this->resolverForFirstNode($outside)->findUserFileById('alice', 42));
	}

	/** Group folders can report no owner at all; that must not block cleanup. */
	public function testAcceptsAFileWithNoOwner(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Team/notes.pad');
		$file->method('getOwner')->willReturn(null);

		$this->assertSame($file, $this->resolverForFirstNode($file)->findUserFileById('alice', 42));
	}

	public function testReturnsNullWhenNothingHasThatId(): void {
		$this->assertNull($this->resolverForFirstNode(null)->findUserFileById('alice', 42));
	}

	public function testRefusesANonPositiveId(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		$this->assertNull((new UserNodeResolver($rootFolder))->findUserFileById('alice', 0));
	}

	private function ownedFile(string $path, string $ownerUid): File {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn($ownerUid);

		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($path);
		$file->method('getOwner')->willReturn($owner);
		return $file;
	}

	private function resolverForFirstNode(mixed $node): UserNodeResolver {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->with(42)->willReturn($node);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
		return new UserNodeResolver($rootFolder);
	}

	private function resolverFor(array $nodes): UserNodeResolver {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->willReturn($nodes);
		return new UserNodeResolver($rootFolder);
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

	/**
	 * The user's own root has the path `/<uid>/files`, without a trailing
	 * slash, so a prefix check against `/<uid>/files/` rejects it — and
	 * create-by-parent cannot create a pad directly in "All files".
	 */
	public function testResolvesTheUserRootItself(): void {
		$root = $this->folderAt('/alice/files');
		$resolver = $this->resolverFor([$root]);

		$this->assertSame($root, $resolver->resolveUserFolderNodeById('alice', 42));
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
}
