<?php

declare(strict_types=1);

namespace OCP\AppFramework\Utility;

if (!interface_exists(ITimeFactory::class)) {
	interface ITimeFactory {
		public function getTime(): int;
	}
}
