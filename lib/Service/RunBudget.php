<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What a run of Etherpad calls may spend, item by item, with a promised
 * total length: time, and patience with an Etherpad that does not answer.
 * A deadline alone bounds when the last call starts, not when it ends, so
 * each call is given what is left, and one that could not finish in it is
 * not started. A few items without an answer read as an outage, and the
 * run ends there rather than paying a timeout for each. A background run
 * asks Etherpad whether it answers at all before it counts one; a request,
 * which someone waits on, counts it at once (forRequest()).
 */
final class RunBudget {
	/** The whole run, as both sweeps promise it. */
	public const DEFAULT_SECONDS = 20.0;

	/** The least a call is given in a background run: with less left, none is started. */
	private const MIN_CALL_TIMEOUT_SECONDS = 2;

	/** The least a call is given in a request, whose budget is a couple of seconds. */
	private const MIN_REQUEST_CALL_TIMEOUT_SECONDS = 1;

	/** Items without an answer a run puts up with before reading them as an outage. */
	private const MAX_FAILURES = 5;

	private float $deadline;
	private int $failures = 0;

	public function __construct(
		private ITimeFactory $clock,
		float $seconds,
		private int $minCallSeconds = self::MIN_CALL_TIMEOUT_SECONDS,
		private bool $probesOutages = true,
	) {
		$this->deadline = $this->now() + $seconds;
	}

	/** A budget for a request: shorter calls, and no call spent on asking whether Etherpad answers. */
	public static function forRequest(ITimeFactory $clock, float $seconds): self {
		return new self($clock, $seconds, self::MIN_REQUEST_CALL_TIMEOUT_SECONDS, false);
	}

	/** Whether an item without an answer is worth a call asking whether Etherpad answers at all. */
	public function probesOutages(): bool {
		return $this->probesOutages;
	}

	/** Whether a call started now could still finish in time. */
	private function fitsAnotherCall(): bool {
		return $this->deadline - $this->now() >= $this->minCallSeconds;
	}

	/** An item Etherpad gave no answer for. */
	public function noteFailure(): void {
		$this->failures++;
	}

	/** Out of time, or out of patience: no further item is started. */
	public function exhausted(): bool {
		return $this->failures >= self::MAX_FAILURES || !$this->fitsAnotherCall();
	}

	/**
	 * The timeout for a call about to start, or null when none would finish
	 * in time any more. For every call after a sweep's first, which the loop
	 * has already checked with exhausted().
	 */
	public function nextCallTimeout(): ?int {
		return $this->fitsAnotherCall() ? $this->callTimeout() : null;
	}

	/**
	 * The timeout for a call about to start under $budget; null without one,
	 * and the client's own applies.
	 *
	 * @throws RunBudgetSpentException when no call would finish in time any more
	 */
	public static function timeoutOf(?self $budget): ?int {
		if ($budget === null) {
			return null;
		}
		return $budget->nextCallTimeout() ?? throw new RunBudgetSpentException('No time left in the run for another Etherpad call.');
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
