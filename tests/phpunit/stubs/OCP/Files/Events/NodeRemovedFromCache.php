<?php

declare(strict_types=1);

namespace OCP\Files\Events;

use OCP\EventDispatcher\Event;
use OCP\Files\Storage\IStorage;

if (!class_exists(NodeRemovedFromCache::class)) {
	class NodeRemovedFromCache extends Event {
		public function __construct(
			private IStorage $storage,
			private string $path,
		) {
		}

		public function getStorage(): IStorage {
			return $this->storage;
		}

		public function getPath(): string {
			return $this->path;
		}
	}
}
