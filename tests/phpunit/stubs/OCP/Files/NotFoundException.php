<?php

declare(strict_types=1);

namespace OCP\Files;

if (!class_exists(NotFoundException::class)) {
	class NotFoundException extends \Exception {
	}
}
