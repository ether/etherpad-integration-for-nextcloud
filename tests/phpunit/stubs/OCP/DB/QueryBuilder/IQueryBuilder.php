<?php

declare(strict_types=1);

namespace OCP\DB\QueryBuilder;

if (!interface_exists(IQueryBuilder::class)) {
	interface IQueryBuilder {
		public const PARAM_INT = 1;
		public const PARAM_NULL = 0;
		public const PARAM_INT_ARRAY = 101;
		public const PARAM_STR_ARRAY = 102;
	}
}
