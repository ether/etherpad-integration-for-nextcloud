<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(IFilenameValidator::class)) {
	interface IFilenameValidator {
		public function isFilenameValid(string $filename): bool;

		public function validateFilename(string $filename): void;
	}
}
