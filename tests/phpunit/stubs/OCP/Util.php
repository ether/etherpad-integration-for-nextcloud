<?php

declare(strict_types=1);

namespace OCP;

if (!class_exists(Util::class)) {
	class Util {
		/** @var list<array{0:string,1:string,2:?string}> */
		public static array $scripts = [];

		public static function addStyle(string $application, string $file): void {
		}

		public static function addScript(string $application, string $file, ?string $afterAppId = null): void {
			self::$scripts[] = [$application, $file, $afterAppId];
		}

		public static function addInitScript(string $application, string $file): void {
		}

		public static function reset(): void {
			self::$scripts = [];
		}
	}
}
