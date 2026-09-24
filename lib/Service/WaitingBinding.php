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
 * A row the sweep takes: the binding's file, pad and state, and the path
 * its file has in the file cache now - null once nothing is left of the
 * file.
 */
final class WaitingBinding {
	public function __construct(
		public readonly int $fileId,
		public readonly string $padId,
		public readonly string $state,
		public readonly ?string $filePath,
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
			state: DbRows::string($row, 'state'),
			filePath: DbRows::nullableString($row, 'file_path'),
		);
	}
}
