<?php

declare(strict_types=1);

namespace OCP\Files\Config;

use OCP\Files\Mount\IMountPoint;
use OCP\IUser;

if (!interface_exists(IMountProviderCollection::class)) {
	interface IMountProviderCollection {
		/** @return IMountPoint */
		public function getHomeMountForUser(IUser $user);
	}
}
