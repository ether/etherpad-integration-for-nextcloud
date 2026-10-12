<?php

declare(strict_types=1);

namespace OCP\Preview;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;

if (!interface_exists(IProviderV2::class)) {
	interface IProviderV2 {
		public function getMimeType(): string;

		public function isAvailable(FileInfo $file): bool;

		public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage;
	}
}
