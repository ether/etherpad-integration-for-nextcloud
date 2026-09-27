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
		 * When the row became a deletion owed. Null in every other state, and
		 * on a deletion owed that never recorded one.
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
	 * No run has put this deletion owed back since it became one: updated_at
	 * is still at deleted_at. A row without that date cannot tell, and counts
	 * as untouched, so its trouble is reported each time. A row a trash
	 * before 1.1.0 wrote may have the two seconds apart, and counts as put
	 * back.
	 */
	public function untouchedSinceOwed(): bool {
		return $this->deletedAt === null || $this->updatedAt <= $this->deletedAt;
	}

	/** A deletion owed, or a restore left undecided: a row the sweep settles. */
	public function isWaiting(): bool {
		return $this->state === BindingService::STATE_PENDING_DELETE || $this->state === BindingService::STATE_RESTORE_PENDING;
	}
}
