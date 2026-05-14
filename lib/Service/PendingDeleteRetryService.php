<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use Psr\Log\LoggerInterface;

class PendingDeleteRetryService {
	public function __construct(
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{
	 *   attempted:int,
	 *   resolved:int,
	 *   failed:int,
	 *   remaining:int
	 * }
	 */
	public function retry(int $limit = 200): array {
		$safeLimit = max(1, $limit);
		$pendingResult = $this->retryPendingDeleteRows(
			$this->bindingService->findByState(BindingService::STATE_PENDING_DELETE, $safeLimit),
		);

		return [
			'attempted' => $pendingResult['attempted'],
			'resolved' => $pendingResult['resolved'],
			'failed' => $pendingResult['failed'],
			'remaining' => $this->countPendingDeletes(),
		];
	}

	/**
	 * @return array{
	 *   attempted:int,
	 *   resolved:int,
	 *   failed:int,
	 *   remaining:int
	 * }
	 */
	public function retryByAge(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit = 200): array {
		$safeLimit = max(1, $limit);
		$pendingResult = $this->retryPendingDeleteRows(
			$this->bindingService->findPendingDeleteByAge($minAgeSeconds, $maxAgeSeconds, $safeLimit),
		);

		return [
			'attempted' => $pendingResult['attempted'],
			'resolved' => $pendingResult['resolved'],
			'failed' => $pendingResult['failed'],
			'remaining' => $this->countPendingDeletes(),
		];
	}

	public function countPendingDeletes(): int {
		return $this->bindingService->countByState(BindingService::STATE_PENDING_DELETE);
	}

	/**
	 * @param array<int,array<string,mixed>> $pending
	 * @return array{attempted:int,resolved:int,failed:int}
	 */
	private function retryPendingDeleteRows(array $pending): array {
		$attempted = 0;
		$resolved = 0;
		$failed = 0;
		foreach ($pending as $row) {
			$fileId = (int)($row['file_id'] ?? 0);
			$padId = (string)($row['pad_id'] ?? '');
			if ($fileId <= 0 || $padId === '') {
				continue;
			}
			$attempted++;
			try {
				$this->etherpadClient->deletePad($padId);
				if (!$this->bindingService->deletePendingDeleteBinding($fileId, $padId)) {
					$this->logger->info('Skipped stale pending delete binding after successful pad delete.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'padId' => $padId,
					]);
					continue;
				}
				$resolved++;
				$this->logger->info('Resolved pending pad delete.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'padId' => $padId,
				]);
				continue;
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
					if (!$this->bindingService->deletePendingDeleteBinding($fileId, $padId)) {
						$this->logger->info('Skipped stale pending delete binding after already-deleted response.', [
							'app' => 'etherpad_nextcloud',
							'fileId' => $fileId,
							'padId' => $padId,
						]);
						continue;
					}
					$resolved++;
					$this->logger->info('Resolved pending delete because pad is already gone.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'padId' => $padId,
					]);
					continue;
				}
				$failed++;
				$this->logger->warning('Pending pad delete retry failed.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'padId' => $padId,
					'exception' => $e,
				]);
			}
		}

		return [
			'attempted' => $attempted,
			'resolved' => $resolved,
			'failed' => $failed,
		];
	}

}
