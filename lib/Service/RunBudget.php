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
 * The time a background run may take, for sweeps that call Etherpad item
 * by item and promise a total run length. A deadline alone bounds when the
 * last call starts, not when it ends: each call is given what is left, and
 * one that could not finish in it is not started.
 */
final class RunBudget {
	/** Below this, a call cannot finish inside the budget. */
	private const MIN_CALL_TIMEOUT_SECONDS = 2;

	private float $deadline;

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
