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

	/** @return array<string,mixed>|null */
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

	/** @return array<int,array<string,mixed>> */
	public function findByState(string $state, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->orderBy('updated_at', 'ASC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
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

	/** @return array<int,array<string,mixed>> */
	public function findPendingDeleteByAge(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit = 100): array {
		$now = $this->timeFactory->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)))
			->andWhere($qb->expr()->isNotNull('deleted_at'))
			->andWhere($qb->expr()->lte(
				'deleted_at',
				$qb->createNamedParameter($now - max(0, $minAgeSeconds), IQueryBuilder::PARAM_INT),
			))
			->orderBy('deleted_at', 'ASC')
			->setMaxResults(max(1, $limit));

		if ($maxAgeSeconds !== null) {
			$qb->andWhere($qb->expr()->gt(
				'deleted_at',
				$qb->createNamedParameter($now - max(0, $maxAgeSeconds), IQueryBuilder::PARAM_INT),
			));
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	public function hasFileCacheEntry(int $fileId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!is_array($row) || !isset($row['cnt'])) {
			return false;
		}
		return (int)$row['cnt'] > 0;
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
			$this->logger->error('Could not create pad binding', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				'exception' => $e,
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

	public function markRestored(int $fileId, string $newPadId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('pad_id', $qb->createNamedParameter($newPadId))
			->set('state', $qb->createNamedParameter(self::STATE_ACTIVE))
			->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)));
		$updated = $qb->executeStatement();
		if ($updated < 1) {
			throw new BindingStateConflictException('State transition conflict while restoring (expected pending_delete).');
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

	public function deleteByFileId(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deletePendingDeleteBinding(int $fileId, string $padId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pad_id', $qb->createNamedParameter($padId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)));
		return $qb->executeStatement() > 0;
	}

	private function assertAccessMode(string $accessMode): void {
		if (!in_array($accessMode, [self::ACCESS_PUBLIC, self::ACCESS_PROTECTED], true)) {
			throw new BindingException('Unsupported access mode: ' . $accessMode);
		}
	}
}
