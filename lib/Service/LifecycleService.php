<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;

/**
 * A .pad file's restore as it arrives, from its listener
 * (RestoreFromTrashListener), and the API's recovery of a file from its
 * own content, which names the file by id. Both are RestoreService's to
 * carry out. A trash leaves the pad as it is (docs/deleting-pads.md).
 */
class LifecycleService {
	public function __construct(
		private UserNodeResolver $userNodeResolver,
		private RestoreService $restoreService,
	) {
	}

	/**
	 * @return array{file_id: int, status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 * @throws \OCP\Files\NotFoundException
	 */
	public function recoverByFileId(string $uid, int $fileId): array {
		return ['file_id' => $fileId] + $this->restoreService->recoverFromSnapshot($this->userNodeResolver->resolveUserFileNodeById($uid, $fileId));
	}

	/**
	 * A restore from the trash.
	 *
	 * @return array{status: string, reason?: string, old_pad_id?: string, new_pad_id?: string}
	 */
	public function handleRestore(File $file): array {
		return $this->restoreService->restore($file);
	}
}
