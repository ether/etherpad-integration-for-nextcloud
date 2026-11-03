<?php

declare(strict_types=1);

namespace OCP;

if (!class_exists(Constants::class)) {
	class Constants {
		public const PERMISSION_READ = 1;
		public const PERMISSION_UPDATE = 2;
		public const FILENAME_INVALID_CHARS = '\\/';
	}
}
