<?php

declare(strict_types=1);

namespace OCP\FullTextSearch\Model;

if (!interface_exists(ISearchResult::class)) {
	interface ISearchResult {
		/** @return IIndexDocument[] */
		public function getDocuments(): array;
	}
}
