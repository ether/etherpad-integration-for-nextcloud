<?php

declare(strict_types=1);

namespace OCP\Files\Config;

if (!interface_exists(ICachedMountInfo::class)) {
	interface ICachedMountInfo {
		public function getStorageId(): int;

		public function getMountPoint(): string;
	}
}
