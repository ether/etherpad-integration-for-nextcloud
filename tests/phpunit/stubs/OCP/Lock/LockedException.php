<?php

declare(strict_types=1);

namespace OCP\Lock;

if (!class_exists(LockedException::class)) {
	class LockedException extends \Exception {
	}
}
