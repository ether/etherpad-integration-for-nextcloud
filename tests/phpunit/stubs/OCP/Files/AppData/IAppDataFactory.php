<?php

declare(strict_types=1);

namespace OCP\Files\AppData;

use OCP\Files\IAppData;

if (!interface_exists(IAppDataFactory::class)) {
	interface IAppDataFactory {
		public function get(string $appId): IAppData;
	}
}
