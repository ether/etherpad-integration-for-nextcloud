<?php

declare(strict_types=1);

namespace OCP\AppFramework\Utility;

use Psr\Clock\ClockInterface;

/**
 * Mirrors vendor/nextcloud/ocp/OCP/AppFramework/Utility/ITimeFactory.php,
 * including the PSR-20 clock it extends. A narrower stub would let a test
 * double claim to be an ITimeFactory while satisfying only the part of it
 * this repo happens to use — and `now()` is the microsecond source the
 * time budgets read, so leaving it out is what kept them on microtime().
 */
if (!interface_exists(ITimeFactory::class)) {
	interface ITimeFactory extends ClockInterface {
		public function getTime(): int;

		public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime;

		public function withTimeZone(\DateTimeZone $timezone): static;

		public function getTimeZone(?string $timezone = null): \DateTimeZone;
	}
}
