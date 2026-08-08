<?php

declare(strict_types=1);

namespace OCP\Files;

use OCP\Files\SimpleFS\ISimpleFolder;

if (!interface_exists(IAppData::class)) {
	interface IAppData {
		public function getFolder(string $name): ISimpleFolder;

		public function newFolder(string $name): ISimpleFolder;

		public function getDirectoryListing(): array;
	}
}
