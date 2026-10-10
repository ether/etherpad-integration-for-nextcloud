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
	/**
	 * The columns a database has, so a statement naming any other - one a
	 * migration never made, or one taken out again - fails here as it
	 * would there: the binding table as the app's migrations make it, and
	 * the file cache and the mount cache as Nextcloud does.
	 */
	public const COLUMNS = [
		'ep_pad_bindings' => ['id', 'file_id', 'pad_id', 'access_mode', 'state', 'deleted_at', 'created_at', 'updated_at'],
		'filecache' => ['fileid', 'storage', 'path', 'path_hash', 'parent', 'name', 'mimetype', 'mimepart', 'size', 'mtime', 'storage_mtime', 'encrypted', 'unencrypted_size', 'etag', 'permissions', 'checksum'],
		'mounts' => ['id', 'storage_id', 'root_id', 'user_id', 'mount_point', 'mount_id', 'mount_provider_class', 'mount_point_hash'],
	];

	/** @var list<int> the rows each query read, in order */
	public array $read = [];

	/**
	 * $fileCache holds what the file cache knows (`fileid`, `storage`,
	 * `path`), for the statements that join it; $mounts what the mount
	 * cache keeps (`storage_id`, `root_id`, `mount_point`).
	 *
	 * @param list<array<string,mixed>> $rows
	 * @param list<array<string,mixed>> $fileCache
	 * @param list<array<string,mixed>> $mounts
	 */
	public function __construct(public array $rows, public array $fileCache = [], public array $mounts = []) {
		foreach (['ep_pad_bindings' => $rows, 'filecache' => $fileCache, 'mounts' => $mounts] as $table => $tableRows) {
			foreach ($tableRows as $row) {
				foreach (array_keys($row) as $column) {
					self::assertColumn($table, $column);
				}
			}
		}
	}

	public static function assertColumn(string $table, string $column): void {
		if (!in_array($column, self::COLUMNS[$table], true)) {
			throw new \LogicException(sprintf('%s has no column %s.', $table, $column));
		}
	}

	public function getQueryBuilder(): IQueryBuilder {
		return new InMemoryBindingQuery($this);
	}

	public function escapeLikeParameter(string $param): string {
		return addcslashes($param, '\\_%');
	}
}
