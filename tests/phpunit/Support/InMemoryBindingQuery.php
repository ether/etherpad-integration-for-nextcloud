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
 * One statement against an InMemoryBindingTable: select, update and delete
 * on the binding table, selects on the file cache, the joins between the
 * two the sweeps use, and counts. Conditions are evaluated, not recorded, so a
 * condition left out changes which rows are hit.
 *
 * A column is named as the statement names it: plain on a table without
 * an alias, `alias.column` once aliased. A condition's second operand is a
 * named parameter or, in a join, a column.
 */
final class InMemoryBindingQuery implements IQueryBuilder {
	private string $statement = 'select';
	private string $table = '';
	private ?string $alias = null;
	/** @var list<array{string,string,string,\Closure(array<string,mixed>): bool}> kind, table, alias and condition of each join */
	private array $joins = [];
	/** @var array<string,mixed> */
	private array $parameters = [];
	/** @var list<\Closure(array<string,mixed>): bool> what a row must meet */
	private array $conditions = [];
	/** @var array<string,string> column and the parameter it is set to */
	private array $assignments = [];
	/** @var list<string> */
	private array $selected = [];
	/** @var list<array{string,string}> */
	private array $aliases = [];
	private ?string $orderBy = null;
	private string $direction = 'ASC';
	private ?int $limit = null;

	public function __construct(private InMemoryBindingTable $db) {
	}

	public function select(string ...$columns): self {
		$this->statement = 'select';
		$this->selected = array_values($columns);
		return $this;
	}

	public function selectAlias(string $column, string $alias): self {
		$this->aliases[] = [$column, $alias];
		return $this;
	}

	public function update(string $table): self {
		$this->statement = 'update';
		$this->table = $table;
		return $this;
	}

	public function delete(string $table): self {
		$this->statement = 'delete';
		$this->table = $table;
		return $this;
	}

	public function from(string $table, ?string $alias = null): self {
		$this->table = $table;
		$this->alias = $alias;
		return $this;
	}

	/** @param \Closure(array<string,mixed>): bool $condition */
	public function innerJoin(string $fromAlias, string $table, string $alias, \Closure $condition): self {
		$this->joins[] = ['inner', $table, $alias, $condition];
		return $this;
	}

	/** @param \Closure(array<string,mixed>): bool $condition */
	public function leftJoin(string $fromAlias, string $table, string $alias, \Closure $condition): self {
		$this->joins[] = ['left', $table, $alias, $condition];
		return $this;
	}

	public function set(string $column, string $parameter): self {
		$this->assignments[$this->column($column)] = $parameter;
		return $this;
	}

	/** @param \Closure(array<string,mixed>): bool $condition */
	public function where(\Closure $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	/** @param \Closure(array<string,mixed>): bool $condition */
	public function andWhere(\Closure $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	/** Either way, so a statement that sorts the wrong way shows. */
	public function orderBy(string $column, string $direction = 'ASC'): self {
		$this->orderBy = $column;
		$this->direction = strtoupper($direction);
		return $this;
	}

	public function setMaxResults(int $limit): self {
		$this->limit = $limit;
		return $this;
	}

	/** Only `COUNT(*)`, selected under an alias: the statement then answers one row with the count. */
	public function createFunction(string $call): string {
		return $call;
	}

	public function createNamedParameter(mixed $value, mixed $type = null): string {
		$name = ':p' . count($this->parameters);
		$this->parameters[$name] = $value;
		return $name;
	}

	/** Its own expression builder, for what these statements use. */
	public function expr(): self {
		return $this;
	}

	/** @return \Closure(array<string,mixed>): bool */
	public function eq(string $column, string $operand): \Closure {
		return fn (array $row): bool => $this->value($row, $column) !== null && (string)$this->value($row, $column) === (string)$this->operand($row, $operand);
	}

	/** @return \Closure(array<string,mixed>): bool */
	public function lte(string $column, string $operand): \Closure {
		return fn (array $row): bool => $this->value($row, $column) !== null && (int)$this->value($row, $column) <= (int)$this->operand($row, $operand);
	}

	/** @return \Closure(array<string,mixed>): bool */
	public function in(string $column, string $parameter): \Closure {
		return fn (array $row): bool => in_array($this->value($row, $column), (array)$this->parameters[$parameter], false);
	}

	/** @return \Closure(array<string,mixed>): bool */
	public function isNull(string $column): \Closure {
		return fn (array $row): bool => $this->value($row, $column) === null;
	}

	/** @return \Closure(array<string,mixed>): bool */
	public function isNotNull(string $column): \Closure {
		return fn (array $row): bool => $this->value($row, $column) !== null;
	}

	/**
	 * @param \Closure(array<string,mixed>): bool ...$conditions
	 * @return \Closure(array<string,mixed>): bool
	 */
	public function orX(\Closure ...$conditions): \Closure {
		return static function (array $row) use ($conditions): bool {
			foreach ($conditions as $condition) {
				if ($condition($row)) {
					return true;
				}
			}
			return false;
		};
	}

	public function executeStatement(): int {
		$hit = [];
		foreach ($this->db->rows as $key => $row) {
			if ($this->meets($row)) {
				$hit[] = $key;
			}
		}
		foreach ($hit as $key) {
			if ($this->statement === 'delete') {
				unset($this->db->rows[$key]);
				continue;
			}
			foreach ($this->assignments as $column => $parameter) {
				$this->db->rows[$key][$column] = $this->parameters[$parameter];
			}
		}
		$this->db->rows = array_values($this->db->rows);
		return count($hit);
	}

	public function executeQuery(): object {
		$rows = [];
		foreach ($this->joined() as $row) {
			if ($this->meets($row)) {
				$rows[] = $row;
			}
		}
		foreach ($this->aliases as [$column, $alias]) {
			if ($column === 'COUNT(*)') {
				return $this->result([[$alias => count($rows)]]);
			}
		}
		if ($this->orderBy !== null) {
			$column = $this->orderBy;
			$sign = $this->direction === 'DESC' ? -1 : 1;
			usort($rows, fn (array $a, array $b): int => $sign * ((int)$this->value($a, $column) <=> (int)$this->value($b, $column)));
		}
		return $this->result(array_map(fn (array $row): array => $this->projected($row), array_slice($rows, 0, $this->limit)));
	}

	/** @param list<array<string,mixed>> $rows */
	private function result(array $rows): object {
		return new class($rows) {
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

	/** @param array<string,mixed> $row */
	private function meets(array $row): bool {
		foreach ($this->conditions as $condition) {
			if (!$condition($row)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The rows of the statement's table, each with its joins, every column
	 * under the name the statement uses for it.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function joined(): array {
		$rows = array_map(fn (array $row): array => $this->named($row, $this->alias), $this->rowsOf($this->table));
		foreach ($this->joins as [$kind, $table, $alias, $condition]) {
			$next = [];
			foreach ($rows as $row) {
				$matched = false;
				foreach ($this->rowsOf($table) as $other) {
					$combined = $row + $this->named($other, $alias);
					if ($condition($combined)) {
						$next[] = $combined;
						$matched = true;
					}
				}
				if (!$matched && $kind === 'left') {
					$next[] = $row;
				}
			}
			$rows = $next;
		}
		return $rows;
	}

	/** @return list<array<string,mixed>> */
	private function rowsOf(string $table): array {
		return $table === 'filecache' ? $this->db->fileCache : $this->db->rows;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function named(array $row, ?string $alias): array {
		if ($alias === null) {
			return $row;
		}
		$named = [];
		foreach ($row as $column => $value) {
			$named[$alias . '.' . $column] = $value;
		}
		return $named;
	}

	/**
	 * The selected columns, named as a database answers: `b.file_id` as
	 * `file_id`, an alias as itself, `*` as every column of the table.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function projected(array $row): array {
		$out = [];
		foreach ($this->selected as $column) {
			if ($column === '*') {
				foreach ($row as $name => $value) {
					if ($this->alias === null ? !str_contains($name, '.') : str_starts_with($name, $this->alias . '.')) {
						$out[$this->alias === null ? $name : substr($name, strlen($this->alias) + 1)] = $value;
					}
				}
				continue;
			}
			$parts = explode('.', $column);
			$out[end($parts)] = $this->value($row, $column);
		}
		foreach ($this->aliases as [$column, $alias]) {
			$out[$alias] = $this->value($row, $column);
		}
		return $out;
	}

	/** @param array<string,mixed> $row */
	private function value(array $row, string $column): mixed {
		return $row[$this->column($column)] ?? null;
	}

	/**
	 * A column as the statement names it, once the table it belongs to -
	 * by its alias, or the statement's own without one - is known to have
	 * it (InMemoryBindingTable::COLUMNS). A function, such as `COUNT(*)`,
	 * is no column.
	 */
	private function column(string $name): string {
		if (str_contains($name, '(')) {
			return $name;
		}
		$parts = explode('.', $name, 2);
		$table = $this->table;
		if (count($parts) === 2 && $parts[0] !== $this->alias) {
			foreach ($this->joins as [, $joined, $alias]) {
				if ($alias === $parts[0]) {
					$table = $joined;
				}
			}
		}
		InMemoryBindingTable::assertColumn($table === 'filecache' ? 'filecache' : 'ep_pad_bindings', end($parts));
		return $name;
	}

	/** @param array<string,mixed> $row */
	private function operand(array $row, string $operand): mixed {
		return array_key_exists($operand, $this->parameters) ? $this->parameters[$operand] : $this->value($row, $operand);
	}
}
