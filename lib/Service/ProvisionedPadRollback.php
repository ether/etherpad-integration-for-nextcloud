<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\SafeError;
use Psr\Log\LoggerInterface;

/**
 * Undoes the pad and the binding row when a flow that provisions a pad
 * fails partway. The `.pad` file is each flow's own business.
 *
 * Which method a flow calls turns on whether a row naming the new pad is
 * the flow's to take back. discardUnlessBoundToFile() lets such a row
 * stand, and the pad with it: a first init's row is the finished half of
 * its job, which the next open completes; a restore calls it where its row
 * should not name the new pad, so a row that does keeps the pad.
 * removeMatchingBindingAndDiscard() takes the row with the pad where the
 * row is the flow's own: a restore's claim, a create's binding write.
 * discardCreated() is for a create's pad before any row can name it.
 *
 * Only for a pad provisioned in the same call: `discardProvisioned` removes
 * a group outright. A flow that adopted an existing pad - the legacy Ownpad
 * migration, and any import built on it - must not come through here.
 */
class ProvisionedPadRollback {
	public function __construct(
		private BindingService $bindingService,
		private ManagedPadLifecycle $padLifecycle,
		private LoggerInterface $logger,
	) {
	}

	/** A row naming this pad keeps it; without one the pad is discarded. */
	public function discardUnlessBoundToFile(int $fileId, string $padId, string $operation): void {
		$this->rollback($fileId, $padId, $operation, keepBinding: true);
	}

	/** A row naming this pad goes, then the pad. One naming another stays. */
	public function removeMatchingBindingAndDiscard(int $fileId, string $padId, string $operation): void {
		$this->rollback($fileId, $padId, $operation, keepBinding: false);
	}

	/**
	 * The pad of a create that failed before its binding write began -
	 * empty or from a template. No row can name the pad yet, so it goes
	 * without a lookup, also where the binding table does not answer.
	 */
	public function discardCreated(int $fileId, string $padId, string $operation): void {
		$this->padLifecycle->discardProvisioned($padId, ['fileId' => $fileId, 'operation' => $operation]);
	}

	private function rollback(int $fileId, string $padId, string $operation, bool $keepBinding): void {
		$context = [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'operation' => $operation,
		];

		try {
			if ($this->bindingService->isBoundTo($fileId, $padId)) {
				if ($keepBinding) {
					return;
				}
				if (!$this->bindingService->deleteActiveBinding($fileId, $padId)) {
					// The matching active row is gone: pending_delete now,
					// replaced, or already removed. Which of those it is
					// cannot be told from here, and one of them still needs
					// the pad, so the pad stays.
					$this->logger->warning('Kept the pad while rolling back: its active binding could no longer be removed.', $context);
					return;
				}
			}
		} catch (\Throwable $bindingError) {
			// Without an answer nothing is destroyed: a pad whose row may
			// still name it is reachable, an orphan is only wasted.
			$this->logger->warning('Could not read or remove the binding while rolling back; keeping its pad.',
				array_merge($context, SafeError::context($bindingError)));
			return;
		}

		$this->padLifecycle->discardProvisioned($padId, $context);
	}
}
