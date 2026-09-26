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
 * the cursor, and the path its file has in the file cache now - null once
 * the file cache has nothing left of it.
 */
final class SweptBinding {
	public function __construct(
		public readonly int $id,
		public readonly Binding $binding,
		public readonly ?string $filePath,
	) {
	}

	/**
	 * @param array<string,mixed> $row
	 * @throws \UnexpectedValueException when a column was not selected, or holds what it cannot
	 */
	public static function fromRow(array $row): self {
		return new self(DbRows::int($row, 'id'), Binding::fromRow($row), DbRows::nullableString($row, 'file_path'));
	}

	/** In a user's trash, or a team folder's on the root storage. */
	public function isInTrash(): bool {
		return $this->filePath !== null
			&& (str_starts_with($this->filePath, BindingService::USER_TRASH_PATH) || str_starts_with($this->filePath, BindingService::TEAM_TRASH_PATH));
	}
}
