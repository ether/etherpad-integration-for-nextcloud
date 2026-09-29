<?php

declare(strict_types=1);

namespace OCP\Files;

use OCP\Files\Storage\IStorage;

if (!interface_exists(IHomeStorage::class)) {
	interface IHomeStorage extends IStorage {
	}
}
