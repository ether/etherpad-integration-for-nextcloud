<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IRequest::class)) {
	interface IRequest {
		/** @return array<string,mixed> */
		public function getParams(): array;

		public function getParam(string $key, mixed $default = null): mixed;

		/** Mirrors OCP\IRequest::getCookie(): the raw value, or null. */
		public function getCookie(string $key): ?string;
	}
}
