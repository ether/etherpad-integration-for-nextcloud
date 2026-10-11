<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
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
	 * The live ones among $sessions at $now: what has not reached
	 * expiredAt().
	 *
	 * @template K of array-key
	 * @param array<K,array{groupID:string,validUntil:int}> $sessions
	 * @return array<K,array{groupID:string,validUntil:int}>
	 */
	public static function live(array $sessions, int $now): array {
		return array_filter($sessions, static fn (array $info): bool => self::expiredAt($info['validUntil']) > $now);
	}

	/**
	 * When a session valid until $validUntil counts as expired: the
	 * clock-skew allowance after it, since Etherpad judges `validUntil` by
	 * its own clock and may still honour a session ours calls dead.
	 *
	 * Capped at PHP_INT_MAX: a listing may carry any positive `validUntil`,
	 * and a sum past the cap would be a float, which ends the revoke or the
	 * sweep with a TypeError.
	 */
	public static function expiredAt(int $validUntil): int {
		return min($validUntil, PHP_INT_MAX - EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS) + EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS;
	}

	/**
	 * $sessions, the latest to expire first: most often whoever was at the
	 * pad last, whom a revoke should reach before the rest.
	 *
	 * @template K of array-key
	 * @param array<K,array{groupID:string,validUntil:int}> $sessions
	 * @return array<K,array{groupID:string,validUntil:int}>
	 */
	public static function latestFirst(array $sessions): array {
		uasort($sessions, static fn (array $a, array $b): int => $b['validUntil'] <=> $a['validUntil']);
		return $sessions;
	}

	/**
	 * $sessions deleted in their order, up to $maxAttempts deletes and
	 * within $budget. `handled` counts what is no longer there, however it
	 * went - the only honest basis for what is left over -, `attempted` the
	 * deletes made, and `refused` says Etherpad refused at least one, which
	 * asks for a retry.
	 *
	 * The ceiling counts attempts, whatever the answer. A refusal does not
	 * end the run, or a session Etherpad never deletes would block the ones
	 * after it; refusals and failures without an answer wear $budget's
	 * patience down - one budget for every within() of a run - and end it
	 * as RunBudget says.
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
					$budget->noteUnanswered(fn (): bool => $this->padLifecycle->answers($budget));
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
}
