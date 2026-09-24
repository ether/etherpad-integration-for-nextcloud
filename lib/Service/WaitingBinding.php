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
 * A row the sweep takes: the binding's file, pad and state, the path its
 * file has in the file cache now - null once nothing is left of the file -
 * and the time the row is aged by: deleted_at for a deletion owed,
 * updated_at for a restore left undecided.
 */
final class WaitingBinding {
	public function __construct(
		public readonly int $fileId,
		public readonly string $padId,
		public readonly string $state,
		public readonly ?string $filePath,
		public readonly ?int $waitingSince = null,
	) {
	}

	/**
	 * Where the file is, by the path the file cache has for it: the prefixes
	 * BindingService's query narrows by. Null for a file in a team folder's
	 * trash on the root storage, which no kind of FileLocation holds.
	 */
	public function location(): ?FileLocation {
		return match (true) {
			$this->filePath === null => FileLocation::Gone,
			str_starts_with($this->filePath, BindingService::USER_TRASH_PATH) => FileLocation::InUserTrash,
			str_starts_with($this->filePath, BindingService::TEAM_TRASH_PATH) => null,
			default => FileLocation::Elsewhere,
		};
	}

	/**
	 * A fetched row, every column it is read from selected.
	 *
	 * @param array<string,mixed> $row
	 * @throws \UnexpectedValueException when a column was not selected, or holds what it cannot
	 */
	public static function fromRow(array $row): self {
		return new self(
			fileId: DbRows::int($row, 'file_id'),
			padId: DbRows::string($row, 'pad_id'),
			state: DbRows::string($row, 'state'),
			filePath: DbRows::nullableString($row, 'file_path'),
			waitingSince: DbRows::nullableInt($row, 'waiting_since'),
		);
	}
}
