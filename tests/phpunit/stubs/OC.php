<?php

declare(strict_types=1);

if (!class_exists(OC::class)) {
	class OC {
		public static mixed $SERVERROOT = null;
		public static mixed $configDir = null;
	}
}
