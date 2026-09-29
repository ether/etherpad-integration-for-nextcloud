<?php

declare(strict_types=1);

namespace OCP\Files\Mount;

if (!interface_exists(IMountPoint::class)) {
	interface IMountPoint {
		/** @return int */
		public function getNumericStorageId();
	}
}
