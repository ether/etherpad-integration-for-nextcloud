<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\DbRows;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ConsistencyCheckService {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Rows whose file the file cache has nothing of, and among them the
	 * vanished ones: still active, never seen deleted for good, which would
	 * have made them `pending_delete` (GoneFilesListener).
	 * The app leaves their pads alone (docs/deleting-pads.md), so an admin
	 * sees them here and decides.
	 *
	 * @return array{
	 *   binding_without_file_count:int,
	 *   vanished_file_count:int,
	 *   samples:array{bindings_without_file:array<int,array<string,mixed>>, vanished_files:array<int,array<string,mixed>>}
	 * }
	 */
	public function run(int $sampleLimit = 25): array {
		$limit = max(1, $sampleLimit);

		return [
			'binding_without_file_count' => $this->count($this->withoutFile()),
			'vanished_file_count' => $this->count($this->vanished($this->withoutFile())),
			'samples' => [
				'bindings_without_file' => $this->sampleBindingsWithoutFile($limit),
				'vanished_files' => $this->sampleVanished($limit),
			],
		];
	}

	/** Rows whose file the file cache has nothing of, counted. */
	private function withoutFile(): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from(BindingService::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->isNull('fc.fileid'));
		return $qb;
	}

	/** Of those, the ones never seen deleted for good: still active. */
	private function vanished(IQueryBuilder $qb): IQueryBuilder {
		$qb->andWhere($qb->expr()->eq('b.state', $qb->createNamedParameter(BindingService::STATE_ACTIVE)));
		return $qb;
	}

	private function count(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		$row = DbRows::one($result->fetch());
		$result->closeCursor();

		if ($row === null || !isset($row['cnt'])) {
			return 0;
		}
		return max(0, (int)$row['cnt']);
	}

	/** @return array<int,array<string,mixed>> */
	private function sampleVanished(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id', 'b.pad_id', 'b.access_mode')
			->from(BindingService::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->isNull('fc.fileid'));
		$this->vanished($qb)
			->orderBy('b.file_id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$rows = DbRows::all($result->fetchAll());
		$result->closeCursor();
		return $rows;
	}

	/** @return array<int,array<string,mixed>> */
	private function sampleBindingsWithoutFile(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id', 'b.pad_id', 'b.access_mode', 'b.state')
			->from(BindingService::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->isNull('fc.fileid'))
			->orderBy('b.file_id', 'ASC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$rows = DbRows::all($result->fetchAll());
		$result->closeCursor();
		return $rows;
	}
}
