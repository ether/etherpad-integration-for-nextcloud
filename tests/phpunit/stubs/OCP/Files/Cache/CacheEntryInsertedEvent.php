<?php

declare(strict_types=1);

namespace OCP\Files\Cache;

if (!class_exists(CacheEntryInsertedEvent::class)) {
	class CacheEntryInsertedEvent extends AbstractCacheEvent {
	}
}
