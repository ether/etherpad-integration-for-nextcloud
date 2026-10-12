<?php

declare(strict_types=1);

namespace OCP\Settings;

if (!interface_exists(IIconSection::class)) {
	interface IIconSection {
		public function getID();

		public function getName();

		public function getPriority();

		public function getIcon();
	}
}
