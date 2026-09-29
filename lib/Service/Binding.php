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
 * One row of the binding table, as the app reads it: which pad a `.pad`
 * file is bound to, how that pad is shared, and where its lifecycle stands
 * (BindingService::STATE_*).
 */
final class Binding {
	public function __construct(
		public readonly int $fileId,
		public readonly string $padId,
		public readonly string $accessMode,
		public readonly string $state,
		/**
		 * When the file was seen deleted for good (pending_delete). Null in
		 * every other state, and on a row an early version left without one.
		 */
		public readonly ?int $deletedAt = null,
		public readonly int $updatedAt = 0,
	) {
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
			accessMode: DbRows::string($row, 'access_mode'),
			state: DbRows::string($row, 'state'),
			deletedAt: DbRows::nullableInt($row, 'deleted_at'),
			updatedAt: DbRows::int($row, 'updated_at'),
		);
	}

	/**
	 * No run has tried this row since the file was seen deleted for good:
	 * updated_at is still at deleted_at. Asked of rows the sweep takes,
	 * which it finds by their deleted_at; every version that writes
	 * pending_delete sets it, 1.1.0-beta.1 included.
	 */
	public function untouchedSinceOwed(): bool {
		return $this->updatedAt <= (int)$this->deletedAt;
	}
}
