<?php

declare(strict_types=1);

namespace OCP\App;

if (!interface_exists(IAppManager::class)) {
	interface IAppManager {
		public function getAppPath(string $appId): string;
	}
}
