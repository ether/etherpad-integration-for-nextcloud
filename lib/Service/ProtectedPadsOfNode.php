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
 * binding table, without a join, says so. No look at whether the folder's
 * storage holds one is taken first: it would be paid on every folder
 * deleted, small ones too, and pay off at most for a large folder in a
 * storage that holds none (measured in docs/architecture.md, "Cost at
 * scale").
 *
 * It runs in the delete's request, so what it takes is bounded: at most
 * MAX_PADS pads, more than a delete can take the sessions of in its
 * budget (PadSessionRevoker), and MAX_FOLDERS folders. Every CHUNK folders
 * of a level take up to two queries, whose limits bound what they return,
 * not what the database reads or how long that takes.
 * What it does not reach is a job's to find (FolderSessionRevoker), with a
 * line that says so.
 */
class ProtectedPadsOfNode {
	private const DIRECTORY = 'httpd/unix-directory';

	/** Folders a level query takes at a time. */
	private const CHUNK = 500;

	/**
	 * Pads a walk takes at most.
	 *
	 * @var int
	 */
	protected const MAX_PADS = 100;

	/**
	 * Folders a walk takes at most, the folder deleted among them.
	 *
	 * @var int
	 */
	protected const MAX_FOLDERS = 10000;

	public function __construct(
		private IDBConnection $db,
		private IMimeTypeLoader $mimeTypes,
		private LoggerInterface $logger,
	) {
	}

	public function of(Node $node): ProtectedPadsFound {
		if (!$node instanceof Folder) {
			return new ProtectedPadsFound($this->padsOf('file_id', [$node->getId()], 1));
		}
		if (!$this->anyProtectedPad()) {
			return new ProtectedPadsFound([]);
		}
		$directory = $this->mimeTypes->getId(self::DIRECTORY);
		$pads = [];
		$known = 1;
		$foldersLeft = false;
		$walked = 0;
		$level = [$node->getId()];
		while ($level !== [] && count($pads) <= static::MAX_PADS) {
			$next = [];
			foreach (array_chunk($level, self::CHUNK) as $folders) {
				$walked += count($folders);
				// One more than there is room for, to tell that there is more.
				array_push($pads, ...$this->padsOf('fc.parent', $folders, max(1, static::MAX_PADS + 1 - count($pads))));
				if (count($pads) > static::MAX_PADS) {
					break;
				}
				if (!$foldersLeft) {
					$room = static::MAX_FOLDERS - $known;
					$found = $this->foldersIn($folders, $directory, max(1, $room + 1));
					if (count($found) > $room) {
						$foldersLeft = true;
						$found = array_slice($found, 0, $room);
					}
					$known += count($found);
					array_push($next, ...$found);
				}
			}
			$level = $next;
		}
		$stoppedShort = count($pads) > static::MAX_PADS || $foldersLeft;
		if ($stoppedShort) {
			$this->logger->info('A folder being deleted holds more than a delete takes the sessions of.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $node->getId(),
				'pads' => count($pads),
				'folders' => $walked,
			]);
		}
		return new ProtectedPadsFound(array_slice($pads, 0, static::MAX_PADS), $stoppedShort);
	}

	/**
	 * The pads of the active protected rows whose file's $column is among
	 * $values: the file's id, or its folder's.
	 *
	 * @param 'file_id'|'fc.parent' $column
	 * @param list<int> $values
	 * @param positive-int $limit
	 * @return list<string>
	 */
	private function padsOf(string $column, array $values, int $limit): array {
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
			->andWhere($qb->expr()->eq('b.state', $qb->createNamedParameter(BindingService::STATE_ACTIVE)))
			->setMaxResults($limit);
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
	 * The folders right under $folders, $limit at most.
	 *
	 * @param list<int> $folders
	 * @param positive-int $limit
	 * @return list<int>
	 */
	private function foldersIn(array $folders, int $directory, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid')
			->from('filecache')
			->where($qb->expr()->in('parent', $qb->createNamedParameter($folders, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter($directory, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$ids = array_map(static fn (array $row): int => DbRows::int($row, 'fileid'), DbRows::all($result->fetchAll()));
		$result->closeCursor();
		return $ids;
	}
}
