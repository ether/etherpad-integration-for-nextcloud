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
}
