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
 * total length: time, and patience with an Etherpad that does not answer
 * or refuses.
 * A deadline alone bounds when the last call starts, not when it ends, so
 * each call is given what is left, and one that could not finish in it is
 * not started. A few items without an answer read as an outage, and the
 * run ends there rather than paying a timeout for each. A background run
 * asks Etherpad whether it answers at all before it counts one; a request,
 * which someone waits on, counts it at once (forRequest()). Refusals end
 * a run too: more in a row than a few items Etherpad will never take, or
 * more in all. One budget for a run, however many calls make it up, so
 * every limit holds for the whole run.
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

	/**
	 * Refusals in a row a run puts up with while Etherpad answers: more than
	 * a few items it will never take, fewer than a run's worth of calls and
	 * lines from an Etherpad that refuses every one.
	 */
	private const MAX_REFUSED_IN_A_ROW = 20;

	/**
	 * Refusals a run puts up with in all: scattered among items that went
	 * through, those in a row start again after each.
	 */
	private const MAX_REFUSED_A_RUN = 50;

	private float $deadline;
	private int $failures = 0;
	private int $refusedInARow = 0;
	private int $refusedInAll = 0;

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

	/** An item Etherpad refused. */
	public function noteRefusal(): void {
		$this->refusedInARow++;
		$this->refusedInAll++;
	}

	/** An item that went through: refusals in a row start again. */
	public function noteDone(): void {
		$this->refusedInARow = 0;
	}

	/** Out of time, or out of patience: no further item is started. */
	public function exhausted(): bool {
		return $this->failures >= self::MAX_FAILURES
			|| $this->refusedInARow >= self::MAX_REFUSED_IN_A_ROW
			|| $this->refusedInAll >= self::MAX_REFUSED_A_RUN
			|| !$this->fitsAnotherCall();
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
