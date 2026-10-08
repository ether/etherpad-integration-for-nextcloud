<?php

declare(strict_types=1);

namespace OCP\Security;

if (!interface_exists(ICrypto::class)) {
	interface ICrypto {
		public function calculateHMAC(string $message, string $password = ''): string;
	}
}
