<?php

declare(strict_types=1);

namespace OCP\Files\Template;

$ocpInterface = __DIR__ . '/../../../../../../vendor/nextcloud/ocp/OCP/Files/Template/ICustomTemplateProvider.php';
if (!interface_exists(ICustomTemplateProvider::class, false) && is_file($ocpInterface)) {
	require_once $ocpInterface;
}

if (!interface_exists(ICustomTemplateProvider::class, false)) {
	interface ICustomTemplateProvider {
		/** @return Template[] */
		public function getCustomTemplates(string $mimetype): array;

		public function getCustomTemplate(string $template): \OCP\Files\File;
	}
}
