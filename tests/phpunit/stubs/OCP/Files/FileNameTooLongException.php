<?php

declare(strict_types=1);

namespace OCP\Files;

if (!class_exists(FileNameTooLongException::class)) {
	class FileNameTooLongException extends InvalidPathException {
	}
}
