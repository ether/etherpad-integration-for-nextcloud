<?php

declare(strict_types=1);

namespace OCP\Http\Client;

if (!class_exists(LocalServerException::class)) {
	class LocalServerException extends \Exception {
	}
}
