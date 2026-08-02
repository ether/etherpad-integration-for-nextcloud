<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(IRootFolder::class)) {
	// Nextcloud's IRootFolder is a Folder; the app relies on that to walk
	// into appdata.
	interface IRootFolder extends Folder {
		public function getAppDataDirectoryName(): string;
		public function getUserFolder(string $uid): Folder;

		/** @return array<int,mixed> */
		public function getById(int $id): array;
	}
}
