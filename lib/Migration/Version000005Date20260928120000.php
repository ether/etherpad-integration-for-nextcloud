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
 * From 1.1.0-beta.1, whose trash deleted a file's pad, to a trash that
 * keeps it (docs/deleting-pads.md).
 *
 * A trash that could not reach Etherpad left its row `pending_delete`, the
 * pad's deletion owed. Whose file the file cache still has - in a trash, or
 * back in Files - is an active row again: its pad was kept, and the trash
 * keeps it now. Whose file is gone for good stays, and the sweep of files
 * gone for good takes its pad.
 *
 * The setting that said whether a trash deleted pads, `delete_on_trash`,
 * now says whether a file deleted for good takes its pad along:
 * `delete_pad_with_file`, with the value the admin gave the old one.
 *
 * @psalm-api
 */
class Version000005Date20260928120000 extends SimpleMigrationStep {
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
			$qb->update(BindingService::TABLE)
				->set('state', $qb->createNamedParameter(BindingService::STATE_ACTIVE))
				->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(BindingService::STATE_PENDING_DELETE)));
			$qb->executeStatement();
		}
	}
}
