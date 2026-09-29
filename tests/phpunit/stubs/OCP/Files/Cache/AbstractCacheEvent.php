<?php

declare(strict_types=1);

namespace OCP\Files\Cache;

use OCP\EventDispatcher\Event;
use OCP\Files\Storage\IStorage;

if (!class_exists(AbstractCacheEvent::class)) {
	class AbstractCacheEvent extends Event {
		public function __construct(
			protected IStorage $storage,
			protected string $path,
			protected int $fileId,
			protected int $storageId,
		) {
		}

		public function getStorage(): IStorage {
			return $this->storage;
		}

		public function getPath(): string {
			return $this->path;
		}

		public function getFileId(): int {
			return $this->fileId;
		}

		public function getStorageId(): int {
			return $this->storageId;
		}
	}
}
