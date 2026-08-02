<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Template;

use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCP\Files\File;
use OCP\Files\Template\ICustomTemplateProvider;
use OCP\Files\Template\Template;
use Psr\Log\LoggerInterface;

/**
 * Offers the templates an admin shares with everyone in Nextcloud's template
 * picker, next to each user's own — see docs/templates.md.
 *
 * @psalm-api
 */
class PadTemplateProvider implements ICustomTemplateProvider {
	private const GLOBAL_ID_PREFIX = 'global:';

	public function __construct(
		private PadTemplateStorage $storage,
		private LoggerInterface $logger,
	) {
	}

	public function getCustomTemplates(string $mimetype): array {
		if ($mimetype !== 'application/x-etherpad-nextcloud') {
			return [];
		}

		return $this->globalTiles();
	}

	/**
	 * Templates an admin uploaded. They carry their own content and access
	 * mode, so nothing here decides either.
	 *
	 * @return list<Template>
	 */
	private function globalTiles(): array {
		try {
			$files = $this->storage->globalTemplates();
		} catch (\Throwable $e) {
			// The picker must still open. The admin page reads the same list
			// without this guard, so the failure stays visible where it can be
			// acted on.
			$this->logger->warning('Could not list the shared pad templates.', [
				'app' => 'etherpad_nextcloud',
				'exception' => $e,
			]);
			return [];
		}

		$tiles = [];
		foreach ($files as $file) {
			$tiles[] = new Template(self::class, self::GLOBAL_ID_PREFIX . $file->getName(), $file);
		}
		return $tiles;
	}

	public function getCustomTemplate(string $template): File {
		if (str_starts_with($template, self::GLOBAL_ID_PREFIX)) {
			$file = $this->storage->globalTemplate(substr($template, strlen(self::GLOBAL_ID_PREFIX)));
			if ($file !== null) {
				return $file;
			}
		}

		throw new \RuntimeException('Unknown pad template: ' . $template);
	}
}
