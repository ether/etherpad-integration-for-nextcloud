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
 * `gone_after`, the time from which the pad of a file seen deleted for good
 * may go. Indexed, since the sweep asks for the rows that have one.
 *
 * @psalm-api
 */
class Version000005Date20260927180000 extends SimpleMigrationStep {
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
		if (!$table->hasColumn('gone_after')) {
			$table->addColumn('gone_after', 'bigint', [
				'notnull' => false,
			]);
			$changed = true;
		}
		if (!$table->hasIndex('ep_bind_gone_idx')) {
			$table->addIndex(['gone_after'], 'ep_bind_gone_idx');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
