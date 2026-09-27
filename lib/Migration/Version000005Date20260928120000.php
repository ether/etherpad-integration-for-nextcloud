<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use Closure;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Util\DbRows;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * From a trash that deleted a file's pad to one that keeps it
 * (docs/deleting-pads.md). The rows that waited under the old way:
 *
 * - `pending_delete` whose file the file cache has - in a trash, or back
 *   in Files: the pad was kept, its deletion owed. The trash keeps pads
 *   now, so the row is active again.
 * - `pending_delete` whose file is gone for good: the pad's deletion is
 *   still owed, and the sweep of files gone for good takes it.
 * - `restore_pending`: a file back from the trash whose pad could not be
 *   checked then. It is the file's pad; should Etherpad have lost it, the
 *   next open offers a new one from the file.
 *
 * And the setting that said whether a trash deleted pads, `delete_on_trash`,
 * now says whether a file deleted for good takes its pad along:
 * `delete_on_permanent_delete`, with the value the admin gave the old one.
 *
 * @psalm-api
 */
class Version000005Date20260928120000 extends SimpleMigrationStep {
	/** A state that no longer exists, but rows from before may still be in. */
	private const RESTORE_PENDING = 'restore_pending';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
		private AppConfigService $appConfig,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->appConfig->takeOverDeleteOnTrash();
		if (!$schemaClosure()->hasTable(BindingService::TABLE)) {
			return;
		}
		$now = $this->timeFactory->getTime();

		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id')
			->from(BindingService::TABLE, 'b')
			->innerJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->eq('b.state', $qb->createNamedParameter(BindingService::STATE_PENDING_DELETE)));
		$result = $qb->executeQuery();
		$kept = array_map(static fn (array $row): int => DbRows::int($row, 'file_id'), DbRows::all($result->fetchAll()));
		$result->closeCursor();

		foreach (array_chunk($kept, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$this->reactivate($qb, $now)
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(BindingService::STATE_PENDING_DELETE)));
			$qb->executeStatement();
		}

		$qb = $this->db->getQueryBuilder();
		$this->reactivate($qb, $now)
			->where($qb->expr()->eq('state', $qb->createNamedParameter(self::RESTORE_PENDING)));
		$qb->executeStatement();
	}

	private function reactivate(IQueryBuilder $qb, int $now): IQueryBuilder {
		return $qb->update(BindingService::TABLE)
			->set('state', $qb->createNamedParameter(BindingService::STATE_ACTIVE))
			->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
	}
}
