<?php

declare(strict_types=1);

namespace OCP\App;

if (!class_exists(AppPathNotFoundException::class)) {
	class AppPathNotFoundException extends \Exception {
	}
}
