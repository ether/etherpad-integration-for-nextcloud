<?php

declare(strict_types=1);

namespace OCP\User;

if (!class_exists(NoUserException::class)) {
	class NoUserException extends \Exception {
	}
}
