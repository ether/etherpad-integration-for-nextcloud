<?php

declare(strict_types=1);

namespace OCP\Files\Storage;

if (!interface_exists(IStorage::class)) {
	interface IStorage {
		/** @throws \OCP\Files\InvalidPathException */
		public function verifyPath(string $path, string $fileName);

		/** @return string */
		public function getId();

		/** @return bool */
		public function instanceOfStorage(string $class);

		/** @return string|false */
		public function file_get_contents(string $path);
	}
}
