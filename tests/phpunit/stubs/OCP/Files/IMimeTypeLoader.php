<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(IMimeTypeLoader::class)) {
	interface IMimeTypeLoader {
		public function getMimetypeById(int $id): ?string;

		public function getId(string $mimetype): int;

		public function exists(string $mimetype): bool;

		public function reset(): void;

		public function updateFilecache(string $ext, int $mimeTypeId): int;
	}
}
