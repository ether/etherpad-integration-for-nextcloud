<?php

declare(strict_types=1);

namespace OCP\Files;

if (!class_exists(ReservedWordException::class)) {
	class ReservedWordException extends InvalidPathException {
	}
}
