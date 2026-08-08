<?php

declare(strict_types=1);

namespace OCP\Lock;

if (!interface_exists(ILockingProvider::class)) {
	interface ILockingProvider {
		public const LOCK_SHARED = 1;
		public const LOCK_EXCLUSIVE = 2;

		public function isLocked(string $path, int $type): bool;

		public function acquireLock(string $path, int $type, ?string $readablePath = null): void;

		public function releaseLock(string $path, int $type): void;

		public function changeLock(string $path, int $targetType): void;

		public function releaseAll(): void;
	}
}
