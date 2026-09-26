<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The binding table as rows in memory, for the conditional writes that
 * decide which of two flows a row belongs to. A builder that only records
 * its conditions shows what a statement says; this one evaluates them, so
 * a condition left out changes which rows are hit, and a test sees it.
 */
final class InMemoryBindingTable implements IDBConnection {
	/** @var list<array<string,mixed>> */
	public array $rows;

	/**
	 * A row given without replaced_pad_id has it NULL, as the column
	 * defaults to in the table.
	 *
	 * @param list<array<string,mixed>> $rows
	 */
	public function __construct(array $rows) {
		$this->rows = array_map(static fn (array $row): array => $row + ['replaced_pad_id' => null], $rows);
	}

	public function getQueryBuilder(): IQueryBuilder {
		return new InMemoryBindingQuery($this);
	}

	public function escapeLikeParameter(string $param): string {
		return addcslashes($param, '\\_%');
	}
}
