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
 * Settles restores left undecided because Etherpad could not say whether
 * the file's pad still existed. Only the row changes: a pad that is there
 * is bound again, a pad that is gone lets the row go, and no answer leaves
 * it for the next run. No pad is ever deleted from here.
 *
 * @psalm-api
 */
class RestoreRecheckService {
	public function __construct(
		private BindingService $bindingService,
		private ManagedPadLifecycle $padLifecycle,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{checked:int, settled:int, remaining:int} */
	public function recheck(int $limit = 200): array {
		$settled = $this->recheckRows($this->bindingService->findByState(BindingService::STATE_RESTORE_PENDING, max(1, $limit)));
		return [
			...$settled,
			'remaining' => $this->bindingService->countByState(BindingService::STATE_RESTORE_PENDING),
		];
	}

	public function recheckByAge(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit = 200): void {
		$this->recheckRows($this->bindingService->findRestorePendingByAge($minAgeSeconds, $maxAgeSeconds, max(1, $limit)));
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{checked:int, settled:int}
	 */
	private function recheckRows(array $rows): array {
		$checked = 0;
		$settled = 0;
		foreach ($rows as $row) {
			$fileId = (int)($row['file_id'] ?? 0);
			$padId = (string)($row['pad_id'] ?? '');
			if ($fileId <= 0 || $padId === '') {
				continue;
			}
			$checked++;
			try {
				if ($this->settle($fileId, $padId)) {
					$settled++;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not settle a restore that was left undecided.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					...SafeError::context($e),
				]);
			}
		}
		return ['checked' => $checked, 'settled' => $settled];
	}

	private function settle(int $fileId, string $padId): bool {
		$presence = $this->padLifecycle->presenceOf($padId);
		$settled = match ($presence) {
			PadPresence::Present => $this->bindingService->transition($fileId, $padId, BindingService::STATE_RESTORE_PENDING, BindingService::STATE_ACTIVE),
			// Without a row the file is like any other restored without one:
			// opening it offers a new pad made from its snapshot.
			PadPresence::Absent => $this->bindingService->deleteInState($fileId, $padId, BindingService::STATE_RESTORE_PENDING),
			PadPresence::Unknown => false,
		};
		if ($settled) {
			$this->logger->info('Settled a restore that was left undecided.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padFound' => $presence === PadPresence::Present,
			]);
		}
		return $settled;
	}
}
