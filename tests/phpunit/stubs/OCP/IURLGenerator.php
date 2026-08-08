<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IURLGenerator::class)) {
	interface IURLGenerator {
		/** @param array<string,mixed> $parameters */
		public function linkToRoute(string $route, array $parameters = []): string;

		public function getBaseUrl(): string;

		public function getWebroot(): string;

		public function imagePath(string $appName, string $file): string;

		public function getAbsoluteURL(string $url): string;
	}
}
