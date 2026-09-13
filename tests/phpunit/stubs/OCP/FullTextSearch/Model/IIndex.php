<?php

declare(strict_types=1);

namespace OCP\FullTextSearch\Model;

if (!interface_exists(IIndex::class)) {
	interface IIndex {
		public function addOption(string $option, string $value): IIndex;

		public function getOption(string $option, string $default = ''): string;
	}
}
