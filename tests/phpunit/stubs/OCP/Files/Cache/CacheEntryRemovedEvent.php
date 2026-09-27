<?php

declare(strict_types=1);

namespace OCP\Files\Cache;

if (!class_exists(CacheEntryRemovedEvent::class)) {
	class CacheEntryRemovedEvent extends AbstractCacheEvent {
	}
}
