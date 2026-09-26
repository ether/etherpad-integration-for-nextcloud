<?php

declare(strict_types=1);

namespace OCP\Files\Config;

use OCP\IUser;

if (!interface_exists(IUserMountCache::class)) {
	interface IUserMountCache {
		/** @return ICachedMountInfo[] */
		public function getMountsForUser(IUser $user);
	}
}
