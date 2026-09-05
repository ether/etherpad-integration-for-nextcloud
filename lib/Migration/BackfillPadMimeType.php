<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\IDBConnection;

/**
 * @psalm-api
 */
class BackfillPadMimeType implements IRepairStep {
	private const MIME_PAD = 'application/x-etherpad-nextcloud';

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function getName(): string {
		return 'Backfill MIME part for existing .pad files';
	}

	public function run(IOutput $output): void {
		$padMimeId = $this->getMimeId(self::MIME_PAD);
		if ($padMimeId === null) {
			$output->info('Skipping MIME backfill: ' . self::MIME_PAD . ' is not registered.');
			return;
		}

		$mimePart = explode('/', self::MIME_PAD, 2)[0];
		$mimePartId = $this->getMimeId($mimePart);
		if ($mimePartId === null) {
			$output->info('Skipping MIME backfill: the ' . $mimePart . ' mimepart is missing.');
			return;
		}

		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder
			->update('filecache')
			->set('mimepart', $queryBuilder->createNamedParameter($mimePartId, IQueryBuilder::PARAM_INT))
			->where(
				$queryBuilder->expr()->eq(
					'mimetype',
					$queryBuilder->createNamedParameter($padMimeId, IQueryBuilder::PARAM_INT),
				),
			)
			->andWhere(
				$queryBuilder->expr()->neq(
					'mimepart',
					$queryBuilder->createNamedParameter($mimePartId, IQueryBuilder::PARAM_INT),
				),
			);

		$updated = $queryBuilder->executeStatement();
		if ($updated > 0) {
			$output->info(sprintf('Backfilled MIME part for %d .pad files.', $updated));
			return;
		}

		$output->info($this->hasPadRows($padMimeId)
			? 'MIME part was already correct on every .pad file.'
			: 'No file carries the pad MIME type; nothing to repair here.');
	}

	private function hasPadRows(int $padMimeId): bool {
		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder
			->select('fileid')
			->from('filecache')
			->where(
				$queryBuilder->expr()->eq(
					'mimetype',
					$queryBuilder->createNamedParameter($padMimeId, IQueryBuilder::PARAM_INT),
				),
			)
			->setMaxResults(1);

		$result = $queryBuilder->executeQuery();
		$found = $result->fetchOne();
		$result->closeCursor();

		return $found !== false;
	}

	private function getMimeId(string $mime): ?int {
		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder
			->select('id')
			->from('mimetypes')
			->where(
				$queryBuilder->expr()->eq(
					'mimetype',
					$queryBuilder->createNamedParameter($mime),
				),
			)
			->setMaxResults(1);

		$result = $queryBuilder->executeQuery();
		$rawValue = $result->fetchOne();
		$result->closeCursor();

		if ($rawValue === false) {
			return null;
		}

		return (int)$rawValue;
	}
}
