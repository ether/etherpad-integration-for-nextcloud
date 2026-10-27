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
 * Which method a flow calls turns on what is left of its file. A first
 * init works on an empty file, so a row naming the new pad is the finished
 * half of the job and the next open writes the rest. A restore has a file
 * naming a different pad, so a row pointing at this pad contradicts it. A
 * failed create gives up its file, and its row and pad go with it; it
 * asks about the row only once its binding write began (discardCreated()).
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
	 * The pad of a failed create - empty or from a template - with the row
	 * naming it once the binding write began ($bindingAttemptFileId), as
	 * createBinding can commit and still throw. Failing earlier, the pad is
	 * the create's beyond doubt and goes without a database question, which
	 * is what happens when that question cannot be answered.
	 */
	public function discardCreated(string $padId, ?int $bindingAttemptFileId, ?int $fileId, string $operation): void {
		if ($bindingAttemptFileId !== null) {
			$this->removeMatchingBindingAndDiscard($bindingAttemptFileId, $padId, $operation);
			return;
		}
		try {
			$this->padLifecycle->discardProvisioned($padId);
		} catch (\Throwable $cleanupError) {
			// The pad id too, against the rule everywhere else: no row links
			// the file to this pad, so the line is the only way to it.
			$this->logger->warning('Could not cleanup failed Etherpad create', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				'operation' => $operation,
				...SafeError::context($cleanupError),
			]);
		}
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

		try {
			$this->padLifecycle->discardProvisioned($padId);
		} catch (\Throwable $cleanupError) {
			// The active row is gone by here, so nothing maps the file id
			// back to this pad any more: the orphan is only findable by name.
			$this->logger->warning('Could not remove the Etherpad pad while rolling back.',
				array_merge($context, ['padId' => $padId], SafeError::context($cleanupError)));
		}
	}
}
