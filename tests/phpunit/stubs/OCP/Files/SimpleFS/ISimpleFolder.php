<?php

declare(strict_types=1);

namespace OCP\Files\SimpleFS;

if (!interface_exists(ISimpleFolder::class)) {
	interface ISimpleFolder {
		public function getName(): string;

		public function delete(): void;
	}
}
