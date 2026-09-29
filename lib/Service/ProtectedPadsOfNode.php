<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\DbRows;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\Node;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The protected pads a node takes along when it leaves Files: a file's
 * own, or those of every file under a folder - by the files' rows,
 * whatever the files are called.
 *
 * A folder's files are found by walking down from it one level at a time
 * through the file cache's `parent` index: a level's rows by a join, its
 * folders for the next level. A prefix match on the path would read the
 * whole storage, which the file cache indexes for no such match on every
 * database, and a team folder from before groupfolders gave each its own
 * storage shares the root storage with all others. The walk is not taken
 * at all on an instance with no active protected pad: one look at the
 * binding table, without a join, says so.
 *
 * It runs in the delete's request, so it is bounded: it stops at MAX_PADS
 * pads, more than a delete can take the sessions of in its budget
 * (PadSessionRevoker), or at MAX_FOLDERS folders, some forty queries.
 * What it does not reach keeps its sessions until they expire, within six
 * hours, with a line that says so.
 */
class ProtectedPadsOfNode {
	private const DIRECTORY = 'httpd/unix-directory';

	/** Folders a level query takes at a time. */
	private const CHUNK = 500;

	/** Pads a walk finds at most. */
	private const MAX_PADS = 100;

	/** Folders a walk reads at most. */
	private const MAX_FOLDERS = 10000;

	public function __construct(
		private IDBConnection $db,
		private IMimeTypeLoader $mimeTypes,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<string> */
	public function of(Node $node): array {
		if (!$node instanceof Folder) {
			return $this->padsOf('file_id', [$node->getId()]);
		}
		if (!$this->anyProtectedPad()) {
			return [];
		}
		$directory = $this->mimeTypes->getId(self::DIRECTORY);
		$pads = [];
		$read = 0;
		$cut = false;
		$level = [$node->getId()];
		while ($level !== [] && !$cut) {
			$next = [];
			foreach (array_chunk($level, self::CHUNK) as $folders) {
				if (count($pads) > self::MAX_PADS || $read >= self::MAX_FOLDERS) {
					$cut = true;
					break;
				}
				$read += count($folders);
				array_push($pads, ...$this->padsOf('fc.parent', $folders));
				array_push($next, ...$this->foldersIn($folders, $directory));
			}
			$level = $next;
		}
		if ($cut || count($pads) > self::MAX_PADS) {
			$this->logger->info('A folder deleted holds more than a delete takes the sessions of; the rest expire on their own.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $node->getId(),
				'pads' => count($pads),
				'folders' => $read,
			]);
		}
		return array_slice($pads, 0, self::MAX_PADS);
	}

	/**
	 * The pads of the active protected rows whose file's $column is among
	 * $values: the file's id, or its folder's.
	 *
	 * @param 'file_id'|'fc.parent' $column
	 * @param list<int> $values
	 * @return list<string>
	 */
	private function padsOf(string $column, array $values): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.pad_id')
			->from(BindingService::TABLE, 'b');
		if ($column === 'fc.parent') {
			$qb->innerJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'));
		} else {
			$column = 'b.file_id';
		}
		$qb->where($qb->expr()->in($column, $qb->createNamedParameter($values, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('b.access_mode', $qb->createNamedParameter(BindingService::ACCESS_PROTECTED)))
			->andWhere($qb->expr()->eq('b.state', $qb->createNamedParameter(BindingService::STATE_ACTIVE)));
		$result = $qb->executeQuery();
		$pads = array_map(static fn (array $row): string => DbRows::string($row, 'pad_id'), DbRows::all($result->fetchAll()));
		$result->closeCursor();
		return $pads;
	}

	/** Whether any active row is of a protected pad. */
	private function anyProtectedPad(): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id')
			->from(BindingService::TABLE)
			->where($qb->expr()->eq('access_mode', $qb->createNamedParameter(BindingService::ACCESS_PROTECTED)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(BindingService::STATE_ACTIVE)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = DbRows::one($result->fetch());
		$result->closeCursor();
		return $found !== null;
	}

	/**
	 * The folders right under $folders.
	 *
	 * @param list<int> $folders
	 * @return list<int>
	 */
	private function foldersIn(array $folders, int $directory): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid')
			->from('filecache')
			->where($qb->expr()->in('parent', $qb->createNamedParameter($folders, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter($directory, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map(static fn (array $row): int => DbRows::int($row, 'fileid'), DbRows::all($result->fetchAll()));
		$result->closeCursor();
		return $ids;
	}
}
