<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Util\DbRows;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class ConsistencyCheckService {
	/** The rows one update marks. */
	private const MARK_CHUNK = 500;

	/** The ids one call collects: what a budget can mark, in a few megabytes. */
	private const MARK_MAX = 500_000;

	/** What forgetVanished() came to. */
	public const FORGOTTEN = 'forgotten';
	public const NOT_VANISHED = 'not_vanished';
	public const PROTECTED_PAD = 'protected_pad';

	public function __construct(
		private IDBConnection $db,
		private BindingService $bindingService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The vanished rows: files the file cache has nothing of, still active,
	 * never seen deleted for good. A row seen deleted for good is
	 * `pending_delete` (GoneFilesListener) and on its way, whether the
	 * sweep takes it within minutes or deleting is off, so it is no issue.
	 * The app leaves the vanished ones' pads alone (docs/deleting-pads.md),
	 * so an admin sees them here and decides (markVanished()).
	 *
	 * @return array{vanished_file_count:int, samples:array{vanished_files:array<int,array<string,mixed>>}}
	 */
	public function run(int $sampleLimit = 25): array {
		return [
			'vanished_file_count' => $this->countVanished(),
			'samples' => [
				'vanished_files' => $this->sampleVanished(max(1, $sampleLimit)),
			],
		];
	}

	/** How many rows are vanished. */
	public function countVanished(): int {
		return $this->count($this->vanished($this->withoutFile()));
	}

	/**
	 * Up to $limit vanished rows marked seen deleted for good, on an
	 * admin's word only (docs/deleting-pads.md says why): the sweep then
	 * deletes their pads as it does those of any file deleted for good,
	 * asking the file cache once more first. $limit is what the admin was
	 * shown and confirmed, so a list that grew meanwhile - a team folder
	 * deleted as a whole since - takes no more than that.
	 *
	 * Their ids are collected once, MARK_MAX of them at most, and marked in
	 * chunks until $budget is spent: the query reads the whole file cache
	 * whatever its limit, so asked again for every chunk it made a long
	 * list take several calls.
	 *
	 * @return int how many rows the marks changed
	 */
	public function markVanished(RunBudget $budget, int $limit): int {
		$marked = 0;
		foreach (array_chunk($this->vanishedFileIds(min($limit, self::MARK_MAX)), self::MARK_CHUNK) as $chunk) {
			if ($budget->exhausted()) {
				break;
			}
			$marked += $this->bindingService->markGone($chunk);
		}
		if ($marked > 0) {
			// The sweep's lines that follow read as deletions seen; this says
			// whose word they were.
			$this->logger->info('An admin marked the rows of vanished files as deleted for good; the sweep deletes their pads.', [
				'app' => Application::APP_ID,
				'count' => $marked,
			]);
		}
		return $marked;
	}

	/** One vanished file's row marked, as markVanished() marks them: whether it was still vanished, and is marked now. */
	public function markVanishedFile(int $fileId): bool {
		if (!$this->bindingService->isFileGone($fileId) || $this->bindingService->markGone([$fileId]) === 0) {
			return false;
		}
		$this->logger->info('An admin marked the row of a vanished file as deleted for good; the sweep deletes its pad.', [
			'app' => Application::APP_ID,
			'fileId' => $fileId,
		]);
		return true;
	}

	/**
	 * One vanished file's row removed on an admin's word, its public pad
	 * left in Etherpad, which the app no longer looks after. Only while the
	 * row is still vanished - active, its file gone from the file cache -
	 * and only a public pad's (docs/deleting-pads.md says what for, and why
	 * not a protected one). The pad's id goes to the log, the one place
	 * left that knows it.
	 *
	 * @return string FORGOTTEN, NOT_VANISHED, or PROTECTED_PAD for a protected pad, left as it is
	 */
	public function forgetVanished(int $fileId): string {
		$binding = $this->bindingService->findByFileId($fileId);
		if ($binding === null || $binding->state !== BindingService::STATE_ACTIVE || !$this->bindingService->isFileGone($fileId)) {
			return self::NOT_VANISHED;
		}
		if ($binding->accessMode !== BindingService::ACCESS_PUBLIC) {
			return self::PROTECTED_PAD;
		}
		if (!$this->bindingService->deleteInState($fileId, $binding->padId, BindingService::STATE_ACTIVE)) {
			return self::NOT_VANISHED;
		}
		$this->logger->info('An admin removed the row of a vanished file; its pad stays in Etherpad, and the app no longer looks after it.', [
			'app' => Application::APP_ID,
			'fileId' => $fileId,
			'padId' => $binding->padId,
		]);
		return self::FORGOTTEN;
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
		return $this->vanishedRows(['b.file_id', 'b.pad_id', 'b.access_mode'], $limit);
	}

	/**
	 * The first $limit vanished rows, by file id, with $columns.
	 *
	 * @param list<string> $columns
	 * @return array<int,array<string,mixed>>
	 */
	private function vanishedRows(array $columns, int $limit): array {
		$result = $this->firstVanished($columns, $limit)->executeQuery();
		$rows = DbRows::all($result->fetchAll());
		$result->closeCursor();
		return $rows;
	}

	/**
	 * The file ids of the first $limit vanished rows, read row by row: a
	 * list of numbers, not a row each, for as many as MARK_MAX.
	 *
	 * @return list<int>
	 */
	private function vanishedFileIds(int $limit): array {
		$result = $this->firstVanished(['b.file_id'], $limit)->executeQuery();
		$fileIds = [];
		while (($row = DbRows::one($result->fetch())) !== null) {
			$fileIds[] = DbRows::int($row, 'file_id');
		}
		$result->closeCursor();
		return $fileIds;
	}

	/**
	 * @param list<string> $columns
	 */
	private function firstVanished(array $columns, int $limit): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)
			->from(BindingService::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->isNull('fc.fileid'));
		$this->vanished($qb)
			->orderBy('b.file_id', 'ASC')
			->setMaxResults($limit);
		return $qb;
	}
}
