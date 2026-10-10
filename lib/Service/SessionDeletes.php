<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\SafeError;
use Psr\Log\LoggerInterface;

/**
 * Etherpad sessions deleted one call at a time within a background run's
 * budget, for the sweeps that do (ExpiredSessionCollector,
 * GroupSessionRevoker). A logout's and a delete's few seconds in a request
 * count differently, and go their own way (PadSessionRevoker).
 */
class SessionDeletes {
	/**
	 * Refusals in a row a run puts up with while Etherpad answers: more than
	 * a few sessions it will never delete, fewer than a run's worth of calls
	 * and lines from an Etherpad that refuses every delete.
	 */
	private const MAX_REFUSED_IN_A_ROW = 20;

	/**
	 * Refusals a run puts up with in all: scattered among deletes, those in
	 * a row start again after each, and would otherwise multiply a run's
	 * calls and lines by twenty.
	 */
	private const MAX_REFUSED_A_RUN = 50;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private ManagedPadLifecycle $padLifecycle,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The live ones among $sessions at $now: only what is expired on both
	 * clocks is left out, since Etherpad judges `validUntil` against its
	 * own, and a session ours calls dead may still be honoured there.
	 *
	 * @template K of array-key
	 * @param array<K,array{groupID:string,validUntil:int}> $sessions
	 * @return array<K,array{groupID:string,validUntil:int}>
	 */
	public static function live(array $sessions, int $now): array {
		$expiredBefore = $now - EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS;
		return array_filter($sessions, static fn (array $info): bool => $info['validUntil'] > $expiredBefore);
	}

	/**
	 * $sessionIds deleted in their order, up to $max and within $budget.
	 * `handled` counts what is no longer there, however it went - the
	 * only honest basis for what is left over - and `refused` says Etherpad
	 * refused at least one, which asks for a retry.
	 *
	 * A refusal does not end the run: a session Etherpad never deletes would
	 * otherwise block the ones after it, every run. Twenty refusals in a row
	 * do, and fifty in all, so a run makes at most fifty calls past $max. A failure is an outage only as GoneFileSweep reads one - Etherpad
	 * unreachable, and not answering ManagedPadLifecycle::answers() - and a
	 * few outages end the run (RunBudget).
	 *
	 * $stillWanted is asked before each delete; once it says no, the run
	 * ends there and `stopped` says so. $context names whose sessions they
	 * are in the line a refusal leaves, $refusedMessage is that line.
	 *
	 * @param list<array-key> $sessionIds
	 * @param array<string,mixed> $context
	 * @param ?\Closure(): bool $stillWanted
	 * @return array{deleted:int,handled:int,refused:bool,stopped:bool}
	 */
	public function within(RunBudget $budget, array $sessionIds, int $max, array $context, string $refusedMessage, ?\Closure $stillWanted = null): array {
		$deleted = 0;
		$handled = 0;
		$refused = false;
		$refusedInARow = 0;
		$refusedInAll = 0;
		foreach ($sessionIds as $sessionId) {
			if ($handled >= $max || $refusedInARow >= self::MAX_REFUSED_IN_A_ROW || $refusedInAll >= self::MAX_REFUSED_A_RUN || $budget->exhausted()) {
				break;
			}
			if ($stillWanted !== null && !$stillWanted()) {
				return ['deleted' => $deleted, 'handled' => $handled, 'refused' => $refused, 'stopped' => true];
			}
			// Asked after the question, which may have taken the time.
			$timeout = $budget->nextCallTimeout();
			if ($timeout === null) {
				break;
			}
			// An all-digit id comes out of PHP's keys as an int, and
			// everything downstream is typed string.
			$sessionId = (string)$sessionId;
			try {
				$this->etherpadClient->deleteSession($sessionId, $timeout);
				$deleted++;
				$handled++;
				$refusedInARow = 0;
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isSessionAlreadyGone($e)) {
					$handled++;
					$refusedInARow = 0;
					continue;
				}
				$refused = true;
				$refusedInARow++;
				$refusedInAll++;
				try {
					if (EtherpadClientException::isEtherpadUnreachable($e) && !$this->padLifecycle->answers($budget)) {
						$budget->noteFailure();
					}
				} catch (RunBudgetSpentException) {
					// No time left to ask: the budget ends the run before the next.
				}
				// A digest, not the id: a session id is the value of the
				// `sessionID` cookie, the credential itself. The digest is
				// enough to see the same entry failing run after run.
				$this->logger->warning($refusedMessage, [
					'app' => 'etherpad_nextcloud',
					...$context,
					'sessionRef' => substr(hash('sha256', $sessionId), 0, 12),
					...SafeError::context($e, [$sessionId]),
				]);
			}
		}

		return ['deleted' => $deleted, 'handled' => $handled, 'refused' => $refused, 'stopped' => false];
	}
}
