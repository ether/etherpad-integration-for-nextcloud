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
		private AppConfigService $appConfig,
	) {
	}

	/**
	 * @return array{
	 *   binding_without_file_count:int,
	 *   missing_file_count:int,
	 *   gone_file_brake_engaged:bool,
	 *   samples:array{bindings_without_file:array<int,array<string,mixed>>}
	 * }
	 */
	public function run(int $sampleLimit = 25): array {
		$limit = max(1, $sampleLimit);

		return [
			'binding_without_file_count' => $this->countBindingsWithoutFile(),
			'missing_file_count' => $this->countMissingFiles(),
			'gone_file_brake_engaged' => $this->appConfig->isGoneFileBrakeEngaged(),
			'samples' => [
				'bindings_without_file' => $this->sampleBindingsWithoutFile($limit),
			],
		];
	}

	private function countBindingsWithoutFile(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from(BindingService::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->isNull('fc.fileid'));
		return $this->count($qb);
	}

	/**
	 * Active rows whose file went missing without being seen leaving Files:
	 * in their grace period, or held by the brake (GoneFileSweep).
	 */
	private function countMissingFiles(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from(BindingService::TABLE)
			->where($qb->expr()->isNotNull('missing_since'))
			->andWhere($qb->expr()->isNull('trashed_at'))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(BindingService::STATE_ACTIVE)));
		return $this->count($qb);
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
