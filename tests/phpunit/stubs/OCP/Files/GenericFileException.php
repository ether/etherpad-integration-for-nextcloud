<?php

declare(strict_types=1);

namespace OCP\Files;

if (!class_exists(GenericFileException::class)) {
	class GenericFileException extends \Exception {
	}
}
