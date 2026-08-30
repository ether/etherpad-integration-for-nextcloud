<?php

declare(strict_types=1);

namespace OCP\Files;

if (!class_exists(NotPermittedException::class)) {
	class NotPermittedException extends \Exception {
	}
}
