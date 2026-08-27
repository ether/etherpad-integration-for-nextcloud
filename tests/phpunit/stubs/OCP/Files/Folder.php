<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(Folder::class)) {
	interface Folder {
		public function nodeExists(string $path): bool;

		public function get(string $path): mixed;

		/** Mirrors OCP\Files\Folder::getFirstNodeById(): the node or null. */
		public function getFirstNodeById(int $id): mixed;

		public function newFile(string $name, $content = null): mixed;

		public function newFolder(string $path): Folder;

		public function isCreatable(): bool;

		public function getPath(): string;

		public function delete(): void;

		/** @return array<int,mixed> */
		public function getDirectoryListing(): array;
	}
}
