<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingNotCreatedException;
use OCA\EtherpadNextcloud\Exception\BindingMismatchException;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Util\DbRows;
use OCA\EtherpadNextcloud\Util\PadAccessMode;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

class BindingService {
	public const TABLE = 'ep_pad_bindings';
	public const ACCESS_PUBLIC = 'public';
	public const ACCESS_PROTECTED = 'protected';
	public const STATE_ACTIVE = 'active';
	/**
	 * The file was seen deleted for good; its pad goes once the grace is
	 * over (GoneFileSweep). deleted_at says since when.
	 */
	public const STATE_PENDING_DELETE = 'pending_delete';

	/**
	 * Where trashes keep files, as file cache paths relative to their
	 * storage: a user's, and a team folder's on the root storage. A team
	 * folder with its own storage (groupfolders 22 on Nextcloud 34,
	 * measured) uses a bare `trash/` there (GoneFilesListener).
	 */
	public const USER_TRASH_PATH = 'files_trashbin/';
	public const TEAM_TRASH_PATH = '__groupfolders/trash/';

	/** The mount provider of a share's mounts, which isInFiles() does not count. */
	private const SHARE_MOUNT_PROVIDER = 'OCA\\Files_Sharing\\MountProvider';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	public function findByFileId(int $fileId): ?Binding {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = DbRows::one($result->fetch());
		$result->closeCursor();

		return $row === null ? null : Binding::fromRow($row);
	}

	/**
	 * Whether this file's binding names this pad.
	 *
	 * The question a cleanup has to ask before destroying a pad it just
	 * provisioned. `createBinding` and `rebind` can commit and still
	 * throw — the connection drops between the write and its answer — so a
	 * flag set alongside the call says "no row" while a row is sitting
	 * there naming the pad about to be deleted. Only reading it back knows.
	 *
	 * It also separates that from the other way those calls fail: an insert
	 * the unique constraint refused because a concurrent request for the
	 * same file won. That row names a different pad, and is not ours to
	 * touch.
	 *
	 * Throws rather than guessing when the row cannot be read at all. Each
	 * caller decides what to do without an answer, and they all decide the
	 * same way — destroy nothing.
	 */
	public function isBoundTo(int $fileId, string $padId): bool {
		return $this->findByFileId($fileId)?->padId === $padId;
	}

	public function findByPadId(string $padId, ?string $state = null): ?Binding {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('pad_id', $qb->createNamedParameter($padId)))
			->setMaxResults(1);
		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}

		$result = $qb->executeQuery();
		$row = DbRows::one($result->fetch());
		$result->closeCursor();

		return $row === null ? null : Binding::fromRow($row);
	}

	/**
	 * Move one binding from one state to another, but only if it is still
	 * in the first and still names this pad. Whoever finds the row changed
	 * has lost to whoever changed it, and gets false rather than an
	 * exception: that is an answer here, not a fault.
	 */
	public function transition(int $fileId, string $padId, string $from, string $to): bool {
		return $this->rebind($fileId, $padId, $from, $padId, $to);
	}

	/**
	 * transition(), where the row ends up naming another pad. Checked
	 * against the pad it names now as well as its state, so a row that
	 * moved on to a different pad in the meantime is left alone.
	 *
	 * deleted_at dates when the file was seen deleted for good, and of the
	 * states only pending_delete has it: going there sets it anew, going
	 * anywhere else clears it, and a row that stays there keeps it - the
	 * sweep's grace runs from it - while only updated_at moves.
	 */
	public function rebind(int $fileId, string $fromPadId, string $from, string $toPadId, string $to): bool {
		$now = $this->timeFactory->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('pad_id', $qb->createNamedParameter($toPadId))
			->set('state', $qb->createNamedParameter($to))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		if ($to !== self::STATE_PENDING_DELETE) {
			$qb->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
		} elseif ($from !== self::STATE_PENDING_DELETE) {
			$qb->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		}
		$qb->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pad_id', $qb->createNamedParameter($fromPadId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($from)));
		return $qb->executeStatement() > 0;
	}

	/** Remove a binding only if it is still in this state and names this pad. */
	public function deleteInState(int $fileId, string $padId, string $state): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pad_id', $qb->createNamedParameter($padId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		return $qb->executeStatement() > 0;
	}

	/** How many files were seen deleted for good and wait for their pad to go: what the health check and the admin page's check report. */
	public function countPendingDeletes(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)));
		$result = $qb->executeQuery();
		$row = DbRows::one($result->fetch());
		$result->closeCursor();
		return $row === null ? 0 : max(0, DbRows::int($row, 'cnt'));
	}

	/**
	 * How long after a file was seen deleted for good its pad may go: a
	 * margin against an order of events no one has seen yet, before a step
	 * that cannot be undone. The job runs every five minutes anyway.
	 */
	public const GONE_GRACE_SECONDS = 5 * 60;

	/**
	 * The files were seen deleted for good (GoneFilesListener): their
	 * active rows become pending_delete, deleted_at and updated_at now, and
	 * their pads go once the grace is over (GoneFileSweep). A row that waits
	 * already keeps its dates.
	 *
	 * @param list<int> $fileIds
	 * @return int how many rows the update changed: active ones, not those waiting already or gone
	 */
	public function markGone(array $fileIds): int {
		$now = $this->timeFactory->getTime();
		$marked = 0;
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE))
				->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_ACTIVE)));
			$marked += $qb->executeStatement();
		}
		return $marked;
	}

	/**
	 * Of the files a removal from the file cache reported, marks the active
	 * rows of those the file cache has nothing of by now (markGone). A
	 * removal reported under an id that is not the file's - Nextcloud 34 up
	 * to 34.0.4 misnumbers a folder's descendants - so never marks a file
	 * that is still there. The rows are looked up first: most files removed
	 * have none, and cost no look into the file cache.
	 *
	 * @param list<int> $fileIds
	 * @return list<int> the files whose rows it marked
	 */
	public function markIfGone(array $fileIds): array {
		$marked = [];
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id')
				->from(self::TABLE)
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_ACTIVE)));
			$rows = $this->fileIdsOf($qb);
			if ($rows === []) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid')
				->from('filecache')
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($rows, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			$present = array_map(static fn (array $row): int => DbRows::int($row, 'fileid'), DbRows::all($result->fetchAll()));
			$result->closeCursor();
			$gone = array_values(array_diff($rows, $present));
			$this->markGone($gone);
			array_push($marked, ...$gone);
		}
		return $marked;
	}

	/**
	 * Where the file cache has a file: its storage and path, as a removal
	 * from the file cache reports them. Null for a file it does not have.
	 *
	 * @return array{int,string}|null
	 */
	public function placeOf(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('storage', 'path')
			->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = DbRows::one($result->fetch());
		$result->closeCursor();
		return $row === null ? null : [DbRows::int($row, 'storage'), DbRows::string($row, 'path')];
	}

	/**
	 * The files whose rows name the pads in $padIds, in any state - a row
	 * owed a delete too - or null when a pad has none. One query for five
	 * hundred pads: a legacy group can hold thousands, and a pass asks
	 * before it lists a single session.
	 *
	 * @param list<string> $padIds
	 * @return ?list<int> sorted
	 */
	public function filesOfPads(array $padIds): ?array {
		$padIds = array_values(array_unique($padIds));
		$named = [];
		$files = [];
		foreach (array_chunk($padIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('pad_id', 'file_id')
				->from(self::TABLE)
				->where($qb->expr()->in('pad_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = DbRows::one($result->fetch())) !== null) {
				$named[DbRows::string($row, 'pad_id')] = true;
				$files[DbRows::int($row, 'file_id')] = true;
			}
			$result->closeCursor();
		}
		foreach ($padIds as $padId) {
			if (!isset($named[$padId])) {
				return null;
			}
		}
		$fileIds = array_keys($files);
		sort($fileIds);
		return $fileIds;
	}

	/**
	 * Where the file cache has each of $fileIds, as placeOf() reads one:
	 * one query for many, for a sweep that asks again and again. A file it
	 * does not have is left out.
	 *
	 * @param list<int> $fileIds
	 * @return array<int,array{int,string}> by file id
	 */
	public function placesOf(array $fileIds): array {
		$places = [];
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid', 'storage', 'path')
				->from('filecache')
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = DbRows::one($result->fetch())) !== null) {
				$places[DbRows::int($row, 'fileid')] = [DbRows::int($row, 'storage'), DbRows::string($row, 'path')];
			}
			$result->closeCursor();
		}
		ksort($places);
		return $places;
	}

	/**
	 * Whether a user sees what the file cache has at $path on $storage in
	 * Files, through a mount Nextcloud keeps for them: under their `files/`.
	 * A trash is outside it, a folder called `trash` on an external storage
	 * inside. Three things it does not go by:
	 *
	 * - a share's mount: the owner's own shows the file where it is, and a
	 *   share's row stays until its user's next login, rooted at the shared
	 *   file wherever it went, the trash too;
	 * - IUserMountCache, which remembers where a file was for the rest of
	 *   the process: this is asked again while a file may be restored;
	 * - the storage's id, which Nextcloud keeps as a hash once it is longer
	 *   than 64 characters.
	 *
	 * Only the mounts rooted at the file or above it are read, a few: a
	 * storage can hold one for every user of every team folder on it.
	 *
	 * A row from before Nextcloud 24 names no provider and counts as any
	 * other: a team folder's of then must. A share's of then counts too,
	 * until its user's next login renews it, and keeps a trashed file's
	 * sessions until they expire - the safer of the two mistakes.
	 */
	public function isInFiles(int $storage, string $path): bool {
		$above = [''];
		$prefix = '';
		foreach (explode('/', $path) as $segment) {
			$prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
			$above[] = $prefix;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.mount_point')
			->from('mounts', 'm')
			->innerJoin('m', 'filecache', 'f', $qb->expr()->eq('m.root_id', 'f.fileid'))
			->where($qb->expr()->eq('m.storage_id', $qb->createNamedParameter($storage, IQueryBuilder::PARAM_INT)))
			// Implied by the join, and what lets the file cache's index on
			// storage and path hash find the roots.
			->andWhere($qb->expr()->eq('f.storage', $qb->createNamedParameter($storage, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('f.path_hash', $qb->createNamedParameter(array_map('md5', $above), IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('m.mount_provider_class'),
				$qb->expr()->neq('m.mount_provider_class', $qb->createNamedParameter(self::SHARE_MOUNT_PROVIDER)),
			))
			// One home, or many alike: a team folder's mounts, one a user.
			->setMaxResults(20);
		$result = $qb->executeQuery();
		$seen = false;
		while (!$seen && ($row = DbRows::one($result->fetch())) !== null) {
			// A mount inside a user's `files/` - a team folder, an external
			// storage - shows there all it holds; a home, mounted at its
			// user's root, only what is under `files/`.
			$seen = preg_match('#^/[^/]+/files/#', DbRows::string($row, 'mount_point')) === 1
				|| str_starts_with($path, 'files/');
		}
		$result->closeCursor();
		return $seen;
	}

	/**
	 * The files are in the file cache after all - moved to another storage,
	 * which Nextcloud reports as a removal and an insert of the same file,
	 * or a deletion that did not happen: their rows are active again.
	 *
	 * @param list<int> $fileIds
	 */
	public function clearGone(array $fileIds): void {
		$now = $this->timeFactory->getTime();
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('state', $qb->createNamedParameter(self::STATE_ACTIVE))
				->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)));
			$qb->executeStatement();
		}
	}

	/**
	 * The files of the rows on the storage $storageId: all a deleted user's
	 * home takes along.
	 *
	 * @return list<int>
	 */
	public function fileIdsOnStorage(int $storageId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id')
			->from(self::TABLE, 'b')
			->innerJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->eq('fc.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));
		return $this->fileIdsOf($qb);
	}

	/** @return list<int> */
	private function fileIdsOf(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$fileIds = array_map(static fn (array $found): int => DbRows::int($found, 'file_id'), DbRows::all($result->fetchAll()));
		$result->closeCursor();
		return $fileIds;
	}

	/**
	 * A gone file's pad Etherpad refused to delete: the row is touched, and
	 * findGone() passes it by until its next try is due. Touched at least a
	 * second past $seenAt, when the file was seen deleted: a row whose two
	 * dates are equal counts as never tried (Binding::untouchedSinceOwed()),
	 * and one refused in the second it was marked would be tried again at
	 * the next run, and warned about again.
	 */
	public function postponeGone(int $fileId, string $padId, ?int $seenAt = null): void {
		$at = max($this->timeFactory->getTime(), (int)$seenAt + 1);
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('updated_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pad_id', $qb->createNamedParameter($padId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)));
		$qb->executeStatement();
	}

	/**
	 * Rows of files seen deleted for good that the file cache has nothing
	 * left of, and that are due: seen deleted at or before $graceBy, and
	 * never tried since - updated_at is still deleted_at - or last tried at
	 * or before $retryBy (GoneFileSweep). The longest untouched first.
	 *
	 * @return list<Binding>
	 */
	public function findGone(int $limit, int $graceBy, int $retryBy): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id', 'b.pad_id', 'b.access_mode', 'b.state', 'b.deleted_at', 'b.updated_at')
			->from(self::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->eq('b.state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)))
			->andWhere($qb->expr()->isNull('fc.fileid'))
			->andWhere($qb->expr()->lte('b.deleted_at', $qb->createNamedParameter($graceBy, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('b.updated_at', 'b.deleted_at'),
				$qb->expr()->lte('b.updated_at', $qb->createNamedParameter($retryBy, IQueryBuilder::PARAM_INT)),
			))
			->orderBy('b.updated_at', 'ASC')
			->setMaxResults(max(1, $limit));
		$result = $qb->executeQuery();
		$rows = array_map(Binding::fromRow(...), DbRows::all($result->fetchAll()));
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Rows seen deleted for good whose file the file cache still has, seen
	 * so at or before $seenBy: a deletion that did not happen after all -
	 * one rolled back, an account whose files were left. Active again, as
	 * many as $limit; kept, such a row would take the pad of a file a scan
	 * drops later, which the app leaves.
	 *
	 * @return int how many
	 */
	public function clearStaleGone(int $seenBy, int $limit): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id')
			->from(self::TABLE, 'b')
			->innerJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->eq('b.state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)))
			->andWhere($qb->expr()->lte('b.deleted_at', $qb->createNamedParameter($seenBy, IQueryBuilder::PARAM_INT)))
			->setMaxResults(max(1, $limit));
		$fileIds = $this->fileIdsOf($qb);
		$this->clearGone($fileIds);
		return count($fileIds);
	}

	/** Whether the file cache has nothing left of the file: asked once more right before its pad goes. */
	public function isFileGone(int $fileId): bool {
		return $this->placeOf($fileId) === null;
	}

	public function createBinding(int $fileId, string $padId, string $accessMode): void {
		$this->assertAccessMode($accessMode);
		$now = $this->timeFactory->getTime();

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'pad_id' => $qb->createNamedParameter($padId),
				'access_mode' => $qb->createNamedParameter($accessMode),
				'state' => $qb->createNamedParameter(self::STATE_ACTIVE),
				'deleted_at' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			]);

		try {
			$qb->executeStatement();
		} catch (\Throwable $e) {
			// What the insert left is open: it may have committed and still
			// thrown, and on a unique collision another row stands. Whoever
			// lets the pad go - made before this - reads the row again first
			// (ProvisionedPadRollback). Not logged here: every caller reports
			// it, the API's through ApiErrorLog, with this as its cause.
			throw new BindingNotCreatedException('Could not create unique pad binding.', 0, $e);
		}
	}

	/**
	 * Whether the file's row names this pad in this mode, before the pad is
	 * handed out: on open, public or not, sync, sync status and the
	 * read-only view.
	 *
	 * Writes in one case: a row seen deleted for good (`pending_delete`) is
	 * made active again, as each caller holds the file, so its deletion did
	 * not happen. Here rather than at each caller, where one left out would
	 * answer "not active" for a file that is there.
	 */
	public function assertConsistentMapping(int $fileId, string $padId, string $accessMode): void {
		$this->assertAccessMode($accessMode);
		$binding = $this->findByFileId($fileId);
		self::assertNames($binding, $padId, $accessMode);
		if ($binding->state === self::STATE_PENDING_DELETE) {
			// Seen deleted for good, yet here: the deletion did not happen
			// after all, and the file keeps its pad.
			if ($this->transition($fileId, $padId, self::STATE_PENDING_DELETE, self::STATE_ACTIVE)) {
				return;
			}
			// Another request took the row back first - an open beside a
			// sync, or the sweep: the row as it is now says.
			$binding = $this->findByFileId($fileId);
			self::assertNames($binding, $padId, $accessMode);
		}
		if ($binding->state !== self::STATE_ACTIVE) {
			throw new BindingException('Pad binding is not active.');
		}
	}

	/**
	 * The row there, naming this pad in this access mode.
	 *
	 * @psalm-assert !null $binding
	 */
	private static function assertNames(?Binding $binding, string $padId, string $accessMode): void {
		if ($binding === null) {
			throw new MissingBindingException('No binding exists for this file.');
		}
		if ($binding->padId !== $padId) {
			throw new BindingMismatchException('Binding pad ID mismatch.');
		}
		if ($binding->accessMode !== $accessMode) {
			throw new BindingMismatchException('Binding access mode mismatch.');
		}
	}

	/**
	 * Remove one file's active row, and only while it still names this pad.
	 *
	 * Both predicates are in the statement rather than read first and
	 * deleted after: between those two the unique index on `file_id` can be
	 * handed to another pad, and a delete by file id alone would take the
	 * winner's row. Answering false also covers a row whose file was seen
	 * deleted for good meanwhile (`pending_delete`): the sweep has yet to
	 * delete its pad, and the row is the only record of which pad that is.
	 */
	public function deleteActiveBinding(int $fileId, string $padId): bool {
		return $this->deleteInState($fileId, $padId, self::STATE_ACTIVE);
	}

	private function assertAccessMode(string $accessMode): void {
		if (PadAccessMode::tryFrom($accessMode) === null) {
			throw new BindingException('Unsupported access mode: ' . $accessMode);
		}
	}
}
