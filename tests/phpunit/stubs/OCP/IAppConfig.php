<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IAppConfig::class)) {
	interface IAppConfig {
		public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string;

		public function getValueInt(string $app, string $key, int $default = 0, bool $lazy = false): int;

		public function getValueBool(string $app, string $key, bool $default = false, bool $lazy = false): bool;

		public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool;
	}
}
