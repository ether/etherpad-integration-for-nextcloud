<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(Folder::class)) {
	interface Folder {
		public function nodeExists(string $path): bool;

		public function get(string $path): mixed;

		/** Mirrors OCP\Files\Folder::getFirstNodeById(): the node or null. */
		public function getFirstNodeById(int $id): mixed;

		/**
		 * Mirrors OCP\Files\Folder::getById(): every node with this id
		 * inside this folder. It lives on Folder, not only on IRootFolder —
		 * resolving an id through the user's own folder is what sets their
		 * mounts up first.
		 *
		 * @return array<int,mixed>
		 */
		public function getById(int $id): array;

		public function newFile(string $name, $content = null): mixed;

		public function newFolder(string $path): Folder;

		public function isCreatable(): bool;

		public function getPath(): string;

		public function getId(): int;

		/** Mirrors OCP\Files\FileInfo::getStorage() / ::getInternalPath(). */
		public function getStorage(): mixed;

		public function getInternalPath(): string;

		public function delete(): void;

		/** @return array<int,mixed> */
		public function getDirectoryListing(): array;
	}
}
