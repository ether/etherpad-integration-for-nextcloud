<?php

declare(strict_types=1);

namespace OCP\FullTextSearch\Model;

if (!interface_exists(IIndexDocument::class)) {
	interface IIndexDocument {
		public const NOT_ENCODED = 0;
		public const ENCODED_BASE64 = 1;

		public function setContent(string $content, int $encoded = 0): IIndexDocument;
	}
}
