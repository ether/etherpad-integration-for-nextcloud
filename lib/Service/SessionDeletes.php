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
 * Etherpad sessions deleted one call at a time within a budget: a
 * background run's (ExpiredSessionCollector, GroupSessionRevoker), or a
 * logout's and a delete's few seconds in a request (PadSessionRevoker).
 */
class SessionDeletes {
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
	 * $sessions deleted in their order, up to $maxAttempts deletes and
	 * within $budget. `handled` counts what is no longer there, however it
	 * went - the only honest basis for what is left over -, `attempted` the
	 * deletes made, and `refused` says Etherpad refused at least one, which
	 * asks for a retry.
	 *
	 * The ceiling counts attempts: an Etherpad that fails fast - a rotated
	 * api key, a 500 - reaches it as surely as one that deletes. A refusal
	 * does not end the run, or a session Etherpad never deletes would block
	 * the ones after it; twenty in a row do, and fifty in all, counted in
	 * $budget across every call a run makes (RunBudget). A failure
	 * that reads as Etherpad unreachable wears the budget's patience down
	 * (RunBudget): in a background run once Etherpad then does not answer
	 * at all, as GoneFileSweep reads an outage, in a request at once.
	 *
	 * $stillWanted is asked before each delete; once it says no, the run
	 * ends there and `stopped` says so. $context names whose sessions they
	 * are in the line a refusal leaves, $refusedMessage is that line.
	 *
	 * @param array<array-key,array{groupID:string,validUntil:int}> $sessions
	 * @param array<string,mixed> $context
	 * @param ?\Closure(): bool $stillWanted
	 * @return array{deleted:int,handled:int,attempted:int,refused:bool,stopped:bool}
	 */
	public function within(RunBudget $budget, array $sessions, int $maxAttempts, array $context, string $refusedMessage, ?\Closure $stillWanted = null): array {
		$deleted = 0;
		$handled = 0;
		$attempted = 0;
		$refused = false;
		foreach ($sessions as $sessionId => $info) {
			if ($attempted >= $maxAttempts || $budget->exhausted()) {
				break;
			}
			if ($stillWanted !== null && !$stillWanted()) {
				return ['deleted' => $deleted, 'handled' => $handled, 'attempted' => $attempted, 'refused' => $refused, 'stopped' => true];
			}
			// Asked after the question, which may have taken the time.
			$timeout = $budget->nextCallTimeout();
			if ($timeout === null) {
				break;
			}
			// An all-digit id comes out of PHP's keys as an int, and
			// everything downstream is typed string.
			$sessionId = (string)$sessionId;
			$attempted++;
			try {
				$this->etherpadClient->deleteSession($sessionId, $timeout);
				$deleted++;
				$handled++;
				$budget->noteDone();
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isSessionAlreadyGone($e)) {
					$handled++;
					$budget->noteDone();
					continue;
				}
				$refused = true;
				$budget->noteRefusal();
				if (EtherpadClientException::isEtherpadUnreachable($e)) {
					$this->noteOutage($budget);
				}
				// A digest, not the id: a session id is the value of the
				// `sessionID` cookie, the credential itself. The digest is
				// enough to see the same entry failing run after run.
				$this->logger->warning($refusedMessage, [
					'app' => 'etherpad_nextcloud',
					...$context,
					'groupId' => $info['groupID'],
					'sessionRef' => substr(hash('sha256', $sessionId), 0, 12),
					...SafeError::context($e, [$sessionId]),
				]);
			}
		}

		return ['deleted' => $deleted, 'handled' => $handled, 'attempted' => $attempted, 'refused' => $refused, 'stopped' => false];
	}

	/** A failure that reads as Etherpad unreachable, counted as the budget asks. */
	private function noteOutage(RunBudget $budget): void {
		try {
			if (!$budget->probesOutages() || !$this->padLifecycle->answers($budget)) {
				$budget->noteFailure();
			}
		} catch (RunBudgetSpentException) {
			// No time left to ask: the budget ends the run before the next.
		}
	}
}
