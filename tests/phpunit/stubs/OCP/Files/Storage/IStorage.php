<?php

declare(strict_types=1);

namespace OCP\Files\Storage;

if (!interface_exists(IStorage::class)) {
	interface IStorage {
		/** @throws \OCP\Files\InvalidPathException */
		public function verifyPath(string $path, string $fileName);
	}
}
