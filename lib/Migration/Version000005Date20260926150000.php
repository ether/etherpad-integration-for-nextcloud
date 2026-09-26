<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use Closure;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * What the sweep of files gone for good needs on a row (GoneFileSweep):
 * `trashed_at`, when the file was seen going to a trash or being deleted.
 * Indexed, since the sweep asks for the rows that have one.
 *
 * @psalm-api
 */
class Version000005Date20260926150000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(BindingService::TABLE)) {
			return null;
		}
		$table = $schema->getTable(BindingService::TABLE);
		$changed = false;
		if (!$table->hasColumn('trashed_at')) {
			$table->addColumn('trashed_at', 'bigint', [
				'notnull' => false,
			]);
			$changed = true;
		}
		if (!$table->hasIndex('ep_bind_trashed_idx')) {
			$table->addIndex(['trashed_at'], 'ep_bind_trashed_idx');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
