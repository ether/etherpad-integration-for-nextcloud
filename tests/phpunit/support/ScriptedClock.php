<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

/**
 * A clock that hands out a prepared sequence, one instant per `now()`, for
 * code that measures a duration and therefore has to see two different
 * times.
 *
 * Only `now()` advances the script. `getTime()` reads the instant the
 * script is standing on without spending it, because the sequence is sized
 * for the duration being measured — a collaborator asking for the whole
 * second would otherwise take one of the two instants the measurement
 * needs and shift it onto a time nobody wrote down.
 */
class ScriptedClock extends TestClock {
	/** @var list<float> */
	private array $remaining;

	/** @param list<float> $instants unix timestamps, one per `now()`, in order */
	public function __construct(array $instants) {
		$this->remaining = $instants;
	}

	public function now(): \DateTimeImmutable {
		return self::instantOf($this->takeMicros());
	}

	/** The instant the script is standing on, without spending it. */
	public function getTime(): int {
		return intdiv($this->peekMicros(), 1_000_000);
	}

	private function takeMicros(): int {
		$micros = $this->peekMicros();
		array_shift($this->remaining);

		return $micros;
	}

	/**
	 * Running out is the test's bug, not a value to invent.
	 *
	 * Repeating the last instant would answer a duration of zero and let a
	 * test that reads more often than it scripted pass with a number nobody
	 * wrote — which is the outcome this whole double exists to prevent.
	 */
	private function peekMicros(): int {
		if ($this->remaining === []) {
			throw new \LogicException('ScriptedClock ran out of instants; the test read the clock more often than it scripted.');
		}

		return (int)round($this->remaining[0] * 1_000_000);
	}
}
