<?php

declare(strict_types=1);

namespace OCP\Settings;

use OCP\AppFramework\Http\TemplateResponse;

if (!interface_exists(ISettings::class)) {
	interface ISettings {
		public function getForm(): TemplateResponse;

		public function getSection(): string;

		public function getPriority(): int;
	}
}
