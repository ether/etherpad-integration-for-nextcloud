<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What every test clock has in common, and nothing else.
 *
 * The whole of ITimeFactory is implemented, not only the part this repo
 * reads today — a double that satisfies less than the interface it names is
 * a double that stops being one the moment production code reaches for
 * another method. All of it is derived from `now()`, which is the only
 * thing a subclass decides.
 *
 * Deliberately not a base that also carries stepping: a clock that plays a
 * prepared sequence cannot be advanced, and an inherited method that
 * silently does nothing is worse than a few lines of repetition. What is
 * shared here is only what is genuinely the same.
 */
abstract class TestClock implements ITimeFactory {
	abstract public function now(): \DateTimeImmutable;

	public function getTime(): int {
		return (int)$this->now()->format('U');
	}

	public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime {
		if ($time === 'now') {
			return \DateTime::createFromImmutable($this->now())
				->setTimezone($timezone ?? new \DateTimeZone('UTC'));
		}

		return new \DateTime($time, $timezone ?? new \DateTimeZone('UTC'));
	}

	/**
	 * Refused rather than ignored.
	 *
	 * Returning `$this` would drop the zone and answer in UTC anyway, which
	 * is the same silently-does-nothing this class is written to avoid.
	 * Nothing calls it; a future caller should find that out here rather
	 * than through a timestamp in the wrong zone.
	 */
	public function withTimeZone(\DateTimeZone $timezone): static {
		throw new \LogicException(static::class . ' does not carry a timezone; use getTimeZone() or fix the test.');
	}

	public function getTimeZone(?string $timezone = null): \DateTimeZone {
		return new \DateTimeZone($timezone ?? 'UTC');
	}

	/** The instant a subclass hands out, as a DateTimeImmutable in UTC. */
	final protected static function instantOf(int $micros): \DateTimeImmutable {
		$formatted = sprintf('%d.%06d', intdiv($micros, 1_000_000), $micros % 1_000_000);

		return \DateTimeImmutable::createFromFormat('U.u', $formatted, new \DateTimeZone('UTC'))
			?: throw new \LogicException('Could not build an instant from ' . $formatted);
	}
}
