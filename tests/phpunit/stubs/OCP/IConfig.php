<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IConfig::class)) {
	interface IConfig {
		public function getAppValue(string $appName, string $key, string $default = ''): string;

		public function getSystemValueBool(string $key, bool $default): bool;

		public function getSystemValue(string $key, $default = '');

		public function getUserValue(string $uid, string $appName, string $key, string $default = ''): string;

		public function setAppValue(string $appName, string $key, string $value): void;

		public function setUserValue(string $uid, string $appName, string $key, string $value): void;

		public function deleteUserValue(string $uid, string $appName, string $key): void;
	}
}
