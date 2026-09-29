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
 * An index on a row's state and access mode. A folder deleted asks whether
 * any active protected row exists before it walks down (ProtectedPadsOfNode),
 * and on an instance of public pads alone the index on state alone reads
 * every active row to say no.
 *
 * @psalm-api
 */
class Version000006Date20260929120000 extends SimpleMigrationStep {
	private const INDEX = 'ep_bind_state_mode_idx';

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable(BindingService::TABLE);
		if ($table->hasIndex(self::INDEX)) {
			return null;
		}
		$table->addIndex(['state', 'access_mode'], self::INDEX);
		return $schema;
	}
}
