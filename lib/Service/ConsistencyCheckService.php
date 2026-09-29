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
	/** The rows marked in one go: one query finds them, one marks them. */
	private const MARK_CHUNK = 500;

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
	 * The vanished rows marked seen deleted for good, on an admin's word
	 * only (docs/deleting-pads.md says why): the sweep then deletes their
	 * pads as it does those of any file deleted for good, asking the file
	 * cache once more first. In chunks, until none is left or $budget is
	 * spent.
	 *
	 * @return int how many rows were marked
	 */
	public function markVanished(RunBudget $budget): int {
		$marked = 0;
		while (!$budget->exhausted()) {
			$fileIds = array_values(array_map(static fn (array $row): int => DbRows::int($row, 'file_id'), $this->vanishedRows(['b.file_id'], self::MARK_CHUNK)));
			$chunk = count($this->bindingService->markIfGone($fileIds));
			$marked += $chunk;
			// A short chunk was the last; one that marked nothing - its rows
			// changed meanwhile - would only be asked again.
			if (count($fileIds) < self::MARK_CHUNK || $chunk === 0) {
				break;
			}
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

	/** One vanished file's row marked, as markVanished() marks them all: whether it was still vanished. */
	public function markVanishedFile(int $fileId): bool {
		if ($this->bindingService->markIfGone([$fileId]) === []) {
			return false;
		}
		$this->logger->info('An admin marked the row of a vanished file as deleted for good; the sweep deletes its pad.', [
			'app' => Application::APP_ID,
			'fileId' => $fileId,
		]);
		return true;
	}

	/**
	 * One vanished file's row removed on an admin's word, its pad left in
	 * Etherpad, which the app no longer looks after (docs/deleting-pads.md
	 * says what that costs). Only while the row is still vanished - active,
	 * its file gone from the file cache. The pad's id goes to the log, the
	 * one place left that knows it.
	 *
	 * @return ?string the pad left in Etherpad, or null when the file is no longer vanished
	 */
	public function forgetVanished(int $fileId): ?string {
		$binding = $this->bindingService->findByFileId($fileId);
		// Active, which the delete holds it to, and without its file.
		if ($binding === null || !$this->bindingService->isFileGone($fileId) || !$this->bindingService->deleteInState($fileId, $binding->padId, BindingService::STATE_ACTIVE)) {
			return null;
		}
		$this->logger->info('An admin removed the row of a vanished file; its pad stays in Etherpad, and the app no longer looks after it.', [
			'app' => Application::APP_ID,
			'fileId' => $fileId,
			'padId' => $binding->padId,
			'accessMode' => $binding->accessMode,
		]);
		return $binding->padId;
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
		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)
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
}
