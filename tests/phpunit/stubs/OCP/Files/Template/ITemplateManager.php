<?php

declare(strict_types=1);

namespace OCP\Files\Template;

if (!interface_exists(ITemplateManager::class)) {
	interface ITemplateManager {
		public function registerTemplateFileCreator(callable $callback): void;
	}
}
