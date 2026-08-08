<?php

declare(strict_types=1);

namespace OCP\Files;

if (!class_exists(InvalidPathException::class)) {
	class InvalidPathException extends \Exception {
	}
}
