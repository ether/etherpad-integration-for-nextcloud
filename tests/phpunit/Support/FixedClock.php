<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

/**
 * A clock that stands still until a test moves it.
 *
 * Deliberately mutable and shared: a test hands the same instance to every
 * collaborator so that `advance()` moves all of them together, which is
 * what a test about elapsed time needs. Nothing else in a test should hold
 * one it did not create.
 */
class FixedClock extends TestClock {
	public const NOW = 1_767_225_600;

	/** Microseconds, so the clock can move sub-second for the time budgets. */
	private int $micros;

	public function __construct(int $now = self::NOW) {
		$this->micros = $now * 1_000_000;
	}

	public function now(): \DateTimeImmutable {
		return self::instantOf($this->micros);
	}

	/** Whole seconds, for a test that says "and an hour later". */
	public function advance(int $seconds): void {
		$this->micros += $seconds * 1_000_000;
	}

	/** Sub-second, for the time budgets, which read `now()` rather than getTime(). */
	public function advanceMicros(int $micros): void {
		$this->micros += $micros;
	}
}
