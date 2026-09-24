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
 * What a trash or a restore answers: a status, and what that status
 * carries. The listeners log a step that was skipped, and the API answers
 * it with 409 (PadResponseService::lifecycleResponse()). Which file it was
 * about, each caller knows already.
 */
final class LifecycleResult {
	public const TRASHED = 'trashed';
	public const RESTORED = 'restored';
	public const SKIPPED = 'skipped';

	/** @return array{status: string, deleted_at: int, snapshot_persisted: bool, delete_pending: bool} */
	public static function trashed(int $deletedAt, bool $snapshotPersisted, bool $deletePending): array {
		return [
			'status' => self::TRASHED,
			'deleted_at' => $deletedAt,
			'snapshot_persisted' => $snapshotPersisted,
			'delete_pending' => $deletePending,
		];
	}

	/** @return array{status: string, old_pad_id: string, new_pad_id: string} */
	public static function restored(string $oldPadId, string $newPadId): array {
		return [
			'status' => self::RESTORED,
			'old_pad_id' => $oldPadId,
			'new_pad_id' => $newPadId,
		];
	}

	/**
	 * A step that did nothing, and why. Logged at debug level: for a row the
	 * sweep cannot settle yet, the only trace of the reason.
	 *
	 * @return array{status: string, reason: string}
	 */
	public static function skipped(string $reason, int $fileId, LoggerInterface $logger): array {
		$logger->debug('Lifecycle step skipped.', [
			'app' => 'etherpad_nextcloud',
			'reason' => $reason,
			'fileId' => $fileId,
		]);
		return ['status' => self::SKIPPED, 'reason' => $reason];
	}
}
