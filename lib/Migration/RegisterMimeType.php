<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\Files\IMimeTypeLoader;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RegisterMimeType implements IRepairStep {
	private const MIME = 'application/x-etherpad-nextcloud';
	private const MIME_ALIAS = 'etherpad-nextcloud-pad';
	private const APP_ICON_RELATIVE = 'img/filetypes/etherpad-nextcloud-pad.svg';
	private const CORE_ICON_DIR = 'core/img/filetypes';

	public function __construct(
		private IConfig $config,
		private IMimeTypeLoader $mimeTypeLoader,
	) {
	}

	public function getName(): string {
		return 'Register MIME type for .pad files';
	}

	public function run(IOutput $output): void {
		$mimeTypeId = $this->mimeTypeLoader->getId(self::MIME);
		$this->mimeTypeLoader->updateFilecache('pad', $mimeTypeId);

		$configDir = '';
		if (isset(\OC::$configDir) && is_string(\OC::$configDir) && \OC::$configDir !== '') {
			$configDir = rtrim(\OC::$configDir, '/') . '/';
		} else {
			$dataDir = rtrim($this->config->getSystemValueString('datadirectory', ''), '/');
			if ($dataDir !== '') {
				$configDir = dirname($dataDir) . '/config/';
			}
		}
		if ($configDir === '') {
			$output->info('MIME mapping files were not updated: config directory could not be resolved.');
			return;
		}

		$mimetypeToExt = [
			self::MIME => self::MIME_ALIAS,
		];
		$extToMimetype = [
			'pad' => [self::MIME],
		];

		$this->appendToJsonFile($configDir . 'mimetypealiases.json', $mimetypeToExt);
		$this->appendToJsonFile($configDir . 'mimetypemapping.json', $extToMimetype);
		$this->ensureCoreFiletypeIcon($output);

		$output->info('Updated MIME mappings for .pad files and backfilled filecache extension mapping.');
		$output->info('Run `occ maintenance:mimetype:update-js` and `occ maintenance:mimetype:update-db` if needed.');
	}

	private function ensureCoreFiletypeIcon(IOutput $output): void {
		if (!isset(\OC::$SERVERROOT) || !is_string(\OC::$SERVERROOT) || \OC::$SERVERROOT === '') {
			$output->info('Skipping core filetype icon sync: server root is not available.');
			return;
		}

		$serverRoot = rtrim(\OC::$SERVERROOT, '/');
		$appIcon = $serverRoot . '/apps/' . Application::APP_ID . '/' . self::APP_ICON_RELATIVE;
		$coreIconDir = $serverRoot . '/' . self::CORE_ICON_DIR;
		$coreIcon = $coreIconDir . '/' . self::MIME_ALIAS . '.svg';

		if (!is_file($appIcon)) {
			$output->info('Skipping core filetype icon sync: app icon source file is missing.');
			return;
		}
		if (!is_dir($coreIconDir) || !is_writable($coreIconDir)) {
			$output->info('Skipping core filetype icon sync: core filetype directory is not writable.');
			return;
		}

		$iconContents = file_get_contents($appIcon);
		if (!is_string($iconContents) || $iconContents === '') {
			$output->info('Skipping core filetype icon sync: app icon source file is empty/unreadable.');
			return;
		}

		if (is_file($coreIcon) || is_link($coreIcon)) {
			$existing = @file_get_contents($coreIcon);
			if (is_string($existing) && $existing === $iconContents) {
				return;
			}
			@unlink($coreIcon);
		}

		if (@file_put_contents($coreIcon, $iconContents, LOCK_EX) !== false) {
			$output->info('Synchronized core filetype icon via file copy for MIME alias etherpad-nextcloud-pad.');
			return;
		}

		$output->info('Failed to synchronize core filetype icon for MIME alias etherpad-nextcloud-pad.');
	}

	private function appendToJsonFile(string $file, array $mappings): void {
		$current = [];
		if (is_file($file)) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded)) {
				$current = $decoded;
			}
		}

		$updated = self::mergeMimeMappings($current, $mappings);
		file_put_contents($file, json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}

	/**
	 * Merge our `.pad` mapping into the existing mimetype config without
	 * clobbering unrelated entries, and idempotently (re-running yields the
	 * same result). Extracted as a pure function so the contract is testable
	 * without the surrounding filesystem / \OC config-dir resolution.
	 *
	 * @param array<string,mixed> $current
	 * @param array<string,mixed> $mappings
	 * @return array<string,mixed>
	 */
	public static function mergeMimeMappings(array $current, array $mappings): array {
		return array_replace_recursive($current, $mappings);
	}
}
