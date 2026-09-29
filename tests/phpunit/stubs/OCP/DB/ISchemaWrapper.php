<?php

declare(strict_types=1);

namespace OCP\DB;

if (!interface_exists(ISchemaWrapper::class)) {
	interface ISchemaWrapper {
		public function getTable($tableName);

		public function hasTable($tableName);
	}
}
