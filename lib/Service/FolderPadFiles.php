<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\DbRows;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;

/**
 * The bound `.pad` files under a folder, as the file cache has them now:
 * what a trash, a delete or a restore of the folder takes along
 * (LeavingPadsListener).
 *
 * Found by walking down from the folder, one level of folders at a time,
 * through the file cache's `parent` index. A prefix match on the path would
 * be one query, but the file cache has no index for it on every database,
 * and it would read the whole storage: on Postgres, measured, every file of
 * the storage for each folder. A team folder from before groupfolders gave
 * each its own storage shares the root storage with all others. Walking
 * down reads the folders and `.pad` files under this one, and nothing else.
 */
class FolderPadFiles {
	private const DIRECTORY = 'httpd/unix-directory';

	/** Folders a level query takes at a time. */
	private const CHUNK = 500;

	public function __construct(
		private IDBConnection $db,
		private IMimeTypeLoader $mimeTypes,
		private BindingService $bindingService,
	) {
	}

	/** @return list<int> the files under the folder $folderId that have a row */
	public function under(int $folderId): array {
		$directory = $this->mimeTypes->getId(self::DIRECTORY);
		$pads = [];
		$level = [$folderId];
		while ($level !== []) {
			$next = [];
			foreach (array_chunk($level, self::CHUNK) as $folders) {
				foreach ($this->childrenOf($folders, $directory) as $child) {
					if (DbRows::int($child, 'mimetype') === $directory) {
						$next[] = DbRows::int($child, 'fileid');
					} else {
						$pads[] = DbRows::int($child, 'fileid');
					}
				}
			}
			$level = $next;
		}
		return $this->bindingService->boundAmong($pads);
	}

	/**
	 * The folders and `.pad` files right under $folders: the rest of their
	 * children is no concern of this walk, and the database leaves it out.
	 *
	 * @param list<int> $folders
	 * @return list<array<string,mixed>>
	 */
	private function childrenOf(array $folders, int $directory): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid', 'mimetype')
			->from('filecache')
			->where($qb->expr()->in('parent', $qb->createNamedParameter($folders, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('mimetype', $qb->createNamedParameter($directory, IQueryBuilder::PARAM_INT)),
				$qb->expr()->iLike('name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter(PadFileType::SUFFIX))),
			));
		$result = $qb->executeQuery();
		$rows = DbRows::all($result->fetchAll());
		$result->closeCursor();
		return $rows;
	}
}
