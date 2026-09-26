<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\DbRows;

/**
 * An active row as the sweep of files gone for good passes it: its id, for
 * the cursor, and the path its file has in the file cache now.
 */
final class SweptBinding {
	public function __construct(
		public readonly int $id,
		public readonly Binding $binding,
		public readonly string $filePath,
	) {
	}

	/**
	 * @param array<string,mixed> $row
	 * @throws \UnexpectedValueException when a column was not selected, or holds what it cannot
	 */
	public static function fromRow(array $row): self {
		return new self(DbRows::int($row, 'id'), Binding::fromRow($row), DbRows::string($row, 'file_path'));
	}

	/** In a user's trash, or a team folder's on the root storage. */
	public function isInTrash(): bool {
		return str_starts_with($this->filePath, BindingService::USER_TRASH_PATH) || str_starts_with($this->filePath, BindingService::TEAM_TRASH_PATH);
	}

	/**
	 * In Files for sure: a home's `files/`, which a team folder with its own
	 * storage has too, or a team folder's on the root storage. Anything else
	 * the sweep cannot place: the bare `trash/` of a team folder with its
	 * own storage, which no path tells from a folder named so on an
	 * external storage, and any file on an external storage.
	 */
	public function isInFiles(): bool {
		return str_starts_with($this->filePath, 'files/') || preg_match('#^__groupfolders/\d+/#', $this->filePath) === 1;
	}
}
