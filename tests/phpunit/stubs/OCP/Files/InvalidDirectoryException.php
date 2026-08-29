<?php

declare(strict_types=1);

namespace OCP\Files;

if (!class_exists(InvalidDirectoryException::class)) {
	class InvalidDirectoryException extends InvalidPathException {
	}
}
