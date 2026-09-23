<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What a background run may spend, for sweeps that call Etherpad item by
 * item and promise a total run length: time, and patience with an Etherpad
 * that does not answer. A deadline alone bounds when the last call starts,
 * not when it ends, so each call is given what is left, and one that could
 * not finish in it is not started. A few items without an answer read as an
 * outage, and the run ends there rather than paying a timeout for each.
 */
final class RunBudget {
	/** The whole run, as both sweeps promise it. */
	public const DEFAULT_SECONDS = 20.0;

	/** Below this, a call cannot finish inside the budget. */
	private const MIN_CALL_TIMEOUT_SECONDS = 2;

	/** Items without an answer a run puts up with before reading them as an outage. */
	private const MAX_FAILURES = 5;

	private float $deadline;
	private int $failures = 0;

	public function __construct(
		private ITimeFactory $clock,
		float $seconds,
	) {
		$this->deadline = $this->now() + $seconds;
	}

	/** Whether a call started now could still finish in time. */
	public function fitsAnotherCall(): bool {
		return $this->deadline - $this->now() >= self::MIN_CALL_TIMEOUT_SECONDS;
	}

	/** An item Etherpad gave no answer for. */
	public function noteFailure(): void {
		$this->failures++;
	}

	public function failures(): int {
		return $this->failures;
	}

	/** Out of time, or out of patience: no further item is started. */
	public function exhausted(): bool {
		return $this->failures >= self::MAX_FAILURES || !$this->fitsAnotherCall();
	}

	/**
	 * The rest of the budget as a call's timeout, capped so housekeeping is
	 * never more patient than the calls a user waits on.
	 */
	public function callTimeout(): int {
		return (int)max(0, min(floor($this->deadline - $this->now()), EtherpadClient::REQUEST_TIMEOUT_SECONDS));
	}

	/** Sub-second, through the same factory as the rest. */
	private function now(): float {
		return (float)$this->clock->now()->format('U.u');
	}
}
