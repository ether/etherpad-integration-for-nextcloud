<?php

declare(strict_types=1);

namespace Psr\Clock;

if (!interface_exists(ClockInterface::class)) {
	interface ClockInterface {
		public function now(): \DateTimeImmutable;
	}
}
