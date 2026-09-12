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
 * What to undo when a pad, its binding row and its `.pad` file did not all
 * come into being.
 *
 * Two rules, and which one applies turns on what the file already says. A
 * first init works on an empty file, so a row naming the new pad is the
 * finished half of the job and the next open writes the rest - taking it
 * away would trade a working file for a remote call that can fail. Every
 * other flow has written the file, or has one naming a different pad, so a
 * row pointing at this pad contradicts it and goes.
 *
 * Both then discard the pad, and only ever a pad provisioned in the same
 * call: `discardProvisioned` removes a group outright, which is safe here
 * and nowhere else. A flow that adopted an existing pad - the legacy Ownpad
 * migration, and any import built on it - must not use this.
 */
class PadMaterialisationUnwind {
	public function __construct(
		private BindingService $bindingService,
		private ManagedPadLifecycle $padLifecycle,
		private LoggerInterface $logger,
	) {
	}

	/** The row is the finished half of an init that has more to do. */
	public function keepingWhatTheRowClaims(int $fileId, string $padId, string $what): void {
		$this->unwind($fileId, $padId, $what, keepBinding: true);
	}

	/** The row names a pad the file does not, so neither survives. */
	public function takingBackWhatTheRowClaims(int $fileId, string $padId, string $what): void {
		$this->unwind($fileId, $padId, $what, keepBinding: false);
	}

	private function unwind(int $fileId, string $padId, string $what, bool $keepBinding): void {
		$context = [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'padId' => $padId,
			'unwinding' => $what,
		];

		try {
			if ($this->bindingService->isBoundTo($fileId, $padId)) {
				if ($keepBinding) {
					return;
				}
				$this->bindingService->deleteByFileId($fileId);
			}
		} catch (\Throwable $bindingError) {
			// Without an answer nothing is destroyed: a pad whose row may
			// still name it is reachable, an orphan is only wasted.
			$this->logger->warning('Could not read or remove the binding while unwinding; keeping its pad.',
				$context + ['exception' => $bindingError]);
			return;
		}

		try {
			$this->padLifecycle->discardProvisioned($padId);
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not remove the Etherpad pad while unwinding.',
				$context + ['exception' => $cleanupError]);
		}
	}
}
