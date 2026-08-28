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
