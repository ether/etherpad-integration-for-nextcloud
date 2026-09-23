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
	/** @param list<array<string,mixed>> $rows */
	public function __construct(public array $rows) {
	}

	public function getQueryBuilder(): IQueryBuilder {
		return new InMemoryBindingQuery($this);
	}
}
