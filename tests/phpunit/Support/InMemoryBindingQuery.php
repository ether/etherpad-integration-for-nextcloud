<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * One statement against an InMemoryBindingTable. Only what the binding
 * reads and conditional writes use: equality, select, update and delete.
 */
final class InMemoryBindingQuery implements IQueryBuilder {
	private string $statement = 'select';
	/** @var array<string,mixed> */
	private array $parameters = [];
	/** @var list<array{string,string}> column and the parameter it must equal */
	private array $conditions = [];
	/** @var array<string,string> column and the parameter it is set to */
	private array $assignments = [];
	private ?int $limit = null;

	public function __construct(private InMemoryBindingTable $table) {
	}

	public function select(string ...$columns): self {
		$this->statement = 'select';
		return $this;
	}

	public function update(string $table): self {
		$this->statement = 'update';
		return $this;
	}

	public function delete(string $table): self {
		$this->statement = 'delete';
		return $this;
	}

	public function from(string $table): self {
		return $this;
	}

	public function set(string $column, string $parameter): self {
		$this->assignments[$column] = $parameter;
		return $this;
	}

	/** @param array{string,string} $condition */
	public function where(array $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	/** @param array{string,string} $condition */
	public function andWhere(array $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	public function setMaxResults(int $limit): self {
		$this->limit = $limit;
		return $this;
	}

	public function createNamedParameter(mixed $value, ?int $type = null): string {
		$name = ':p' . count($this->parameters);
		$this->parameters[$name] = $value;
		return $name;
	}

	/** Its own expression builder: equality is all these statements use. */
	public function expr(): self {
		return $this;
	}

	/** @return array{string,string} */
	public function eq(string $column, string $parameter): array {
		return [$column, $parameter];
	}

	public function executeStatement(): int {
		$hit = $this->matchingKeys();
		foreach ($hit as $key) {
			if ($this->statement === 'delete') {
				unset($this->table->rows[$key]);
				continue;
			}
			foreach ($this->assignments as $column => $parameter) {
				$this->table->rows[$key][$column] = $this->parameters[$parameter];
			}
		}
		$this->table->rows = array_values($this->table->rows);
		return count($hit);
	}

	public function executeQuery(): object {
		$rows = array_map(fn (int $key): array => $this->table->rows[$key], $this->matchingKeys());
		return new class(array_slice($rows, 0, $this->limit)) {
			/** @param list<array<string,mixed>> $rows */
			public function __construct(private array $rows) {
			}

			/** @return array<string,mixed>|false */
			public function fetch(): array|false {
				return array_shift($this->rows) ?? false;
			}

			/** @return list<array<string,mixed>> */
			public function fetchAll(): array {
				return $this->rows;
			}

			public function closeCursor(): void {
			}
		};
	}

	/** @return list<int> */
	private function matchingKeys(): array {
		$keys = [];
		foreach ($this->table->rows as $key => $row) {
			foreach ($this->conditions as [$column, $parameter]) {
				if ((string)($row[$column] ?? '') !== (string)$this->parameters[$parameter]) {
					continue 2;
				}
			}
			$keys[] = $key;
		}
		return $keys;
	}
}
