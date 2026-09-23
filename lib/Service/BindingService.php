<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\BindingStateConflictException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Util\PadAccessMode;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class BindingService {
	public const TABLE = 'ep_pad_bindings';
	public const ACCESS_PUBLIC = 'public';
	public const ACCESS_PROTECTED = 'protected';
	public const STATE_ACTIVE = 'active';
	public const STATE_PENDING_DELETE = 'pending_delete';
	/**
	 * The file is back from the trash, but whether its pad still exists
	 * could not be told when it came back. Kept rather than guessed: the
	 * pad may hold the only current copy, or may be gone.
	 */
	public const STATE_RESTORE_PENDING = 'restore_pending';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<string,mixed>|null */
	public function findByFileId(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : $row;
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
		$binding = $this->findByFileId($fileId);
		return $binding !== null && (string)$binding['pad_id'] === $padId;
	}

	public function findByPadId(string $padId, ?string $state = null): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('pad_id', $qb->createNamedParameter($padId)))
			->setMaxResults(1);
		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : $row;
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
	 * deleted_at says the file is in the trash, and of the states only
	 * pending_delete means that: going there sets it anew, going anywhere
	 * else clears it.
	 */
	public function rebind(int $fileId, string $fromPadId, string $from, string $toPadId, string $to): bool {
		$now = $this->timeFactory->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('pad_id', $qb->createNamedParameter($toPadId))
			->set('state', $qb->createNamedParameter($to))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		$qb->set('deleted_at', $to === self::STATE_PENDING_DELETE
			? $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)
			: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
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

	public function countByState(string $state): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row) || !isset($row['cnt'])) {
			return 0;
		}
		return max(0, (int)$row['cnt']);
	}

	/**
	 * Deletions owed, aged by when the trash recorded them.
	 *
	 * A row that never had a deleted_at is reached only by a run with
	 * neither bound - the admin page's. Every age bucket compares the date.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findPendingDeleteByAge(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit = 100): array {
		return $this->findWaitingByAge(self::STATE_PENDING_DELETE, 'deleted_at', $minAgeSeconds, $maxAgeSeconds, $limit);
	}

	/**
	 * Restores left undecided, aged by when the row last changed: when the
	 * restore left it waiting, or when a check last found no answer for it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findRestorePendingByAge(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit = 100): array {
		return $this->findWaitingByAge(self::STATE_RESTORE_PENDING, 'updated_at', $minAgeSeconds, $maxAgeSeconds, $limit);
	}

	/**
	 * Rows in one waiting state, oldest first, each with the path its file
	 * has in the file cache now - null once nothing is left of the file.
	 * Left, not inner: a row whose file is gone has no file cache row to
	 * join, and that is the row a sweep most needs to see.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findWaitingByAge(string $state, string $ageColumn, int $minAgeSeconds, ?int $maxAgeSeconds, int $limit): array {
		$now = $this->timeFactory->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id', 'b.pad_id', 'b.state')
			->selectAlias('fc.path', 'file_path')
			->from(self::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->eq('b.state', $qb->createNamedParameter($state)))
			->orderBy('b.' . $ageColumn, 'ASC')
			->setMaxResults(max(1, $limit));
		if ($minAgeSeconds > 0) {
			$qb->andWhere($qb->expr()->lte('b.' . $ageColumn, $qb->createNamedParameter($now - $minAgeSeconds, IQueryBuilder::PARAM_INT)));
		}
		if ($maxAgeSeconds !== null) {
			$qb->andWhere($qb->expr()->gt('b.' . $ageColumn, $qb->createNamedParameter($now - max(0, $maxAgeSeconds), IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
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
			// The insert is what failed, so no row exists to look the pad up
			// through - and by here it has already been created upstream.
			$this->logger->error('Could not create pad binding', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				...SafeError::context($e),
			]);
			throw new BindingException('Could not create unique pad binding.', 0, $e);
		}
	}

	public function assertConsistentMapping(int $fileId, string $padId, string $accessMode): void {
		$this->assertAccessMode($accessMode);
		$binding = $this->findByFileId($fileId);
		if ($binding === null) {
			throw new MissingBindingException('No binding exists for this file.');
		}
		if ((string)$binding['pad_id'] !== $padId) {
			throw new BindingException('Binding pad ID mismatch.');
		}
		if ((string)$binding['access_mode'] !== $accessMode) {
			throw new BindingException('Binding access mode mismatch.');
		}
		if ((string)$binding['state'] !== self::STATE_ACTIVE) {
			throw new BindingException('Pad binding is not active.');
		}
	}

	public function markPendingDelete(int $fileId, int $deletedAtTs): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE))
			->set('deleted_at', $qb->createNamedParameter($deletedAtTs, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_ACTIVE)));
		$updated = $qb->executeStatement();
		if ($updated < 1) {
			throw new BindingStateConflictException('State transition conflict while marking pending_delete (expected active).');
		}
	}

	/**
	 * Remove one file's active row, and only while it still names this pad.
	 *
	 * Both predicates are in the statement rather than read first and
	 * deleted after: between those two the unique index on `file_id` can be
	 * handed to another pad, and a delete by file id alone would take the
	 * winner's row. Answering false also covers the row a trash that could
	 * not reach Etherpad left as `pending_delete`: that row is the only
	 * record of a deletion still owed, and of the pad it is owed for.
	 */
	public function deleteActiveBinding(int $fileId, string $padId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pad_id', $qb->createNamedParameter($padId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_ACTIVE)));
		return $qb->executeStatement() > 0;
	}

	public function deleteByFileId(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function assertAccessMode(string $accessMode): void {
		if (PadAccessMode::tryFrom($accessMode) === null) {
			throw new BindingException('Unsupported access mode: ' . $accessMode);
		}
	}
}
