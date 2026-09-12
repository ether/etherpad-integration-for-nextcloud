<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use Psr\Log\LoggerInterface;

/**
 * Undoes the pad and the binding row when a flow that provisions a pad
 * fails partway. The `.pad` file is each flow's own business.
 *
 * Which of the two methods a flow calls turns on what its file says. A
 * first init works on an empty file, so a row naming the new pad is the
 * finished half of the job and the next open writes the rest. Every other
 * flow has written the file, or has one naming a different pad, so a row
 * pointing at this pad contradicts it.
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

	private function rollback(int $fileId, string $padId, string $operation, bool $keepBinding): void {
		$context = [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'padId' => $padId,
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
				$context + ['exception' => $bindingError]);
			return;
		}

		try {
			$this->padLifecycle->discardProvisioned($padId);
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not remove the Etherpad pad while rolling back.',
				$context + ['exception' => $cleanupError]);
		}
	}
}
