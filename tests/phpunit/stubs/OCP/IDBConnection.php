<?php

declare(strict_types=1);

namespace OCP;

use OCP\DB\QueryBuilder\IQueryBuilder;

if (!interface_exists(IDBConnection::class)) {
	interface IDBConnection {
		public function getQueryBuilder(): IQueryBuilder;

		public function escapeLikeParameter(string $param): string;
	}
}
