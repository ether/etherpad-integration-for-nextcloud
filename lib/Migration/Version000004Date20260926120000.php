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
 * The pad a row's pad replaced, so a file that still names it can be told
 * from one that names anything else (docs/architecture.md, "Which pad a
 * file reaches").
 *
 * @psalm-api
 */
class Version000004Date20260926120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(BindingService::TABLE)) {
			return null;
		}
		$table = $schema->getTable(BindingService::TABLE);
		if ($table->hasColumn('replaced_pad_id')) {
			return null;
		}
		$table->addColumn('replaced_pad_id', 'string', [
			'length' => 255,
			'notnull' => false,
		]);
		return $schema;
	}
}
