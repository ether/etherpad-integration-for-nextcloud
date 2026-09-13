<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * @psalm-api
 */
class RegisterMimeType implements IRepairStep {
	private const MIME_ALIAS = 'etherpad-nextcloud-pad';
	/** Read from mimetypenames.json, which Nextcloud only loads from 32 on. */
	private const MIME_NAME = 'Etherpad';
	private const APP_ICON_RELATIVE = 'img/filetypes/etherpad-nextcloud-pad.svg';
	private const CORE_ICON_DIR = 'core/img/filetypes';

	public function __construct(
		private IMimeTypeLoader $mimeTypeLoader,
		private IAppManager $appManager,
	) {
	}

	public function getName(): string {
		return 'Register MIME type for .pad files';
	}

	public function run(IOutput $output): void {
		if (!isset(\OC::$configDir) || !is_string(\OC::$configDir) || \OC::$configDir === '') {
			throw new \RuntimeException(
				'Cannot register .pad files: the Nextcloud config directory could not be resolved.',
			);
		}
		$configDir = rtrim(\OC::$configDir, '/') . '/';

		// Before any file is touched: this is the half that makes existing
		// .pad files open, and it must not be lost to an unwritable config
		// directory.
		$mimeTypeId = $this->mimeTypeLoader->getId(PadFileType::MIME);
		$this->mimeTypeLoader->updateFilecache(PadFileType::EXTENSION, $mimeTypeId);

		$this->appendToJsonFile($configDir . 'mimetypemapping.json', [
			PadFileType::EXTENSION => [PadFileType::MIME],
		]);

		$complete = $this->appendOptionalMappings(
			$output,
			$configDir . 'mimetypealiases.json',
			[PadFileType::MIME => self::MIME_ALIAS],
			'file-type icon',
		);
		$complete = $this->appendOptionalMappings(
			$output,
			$configDir . 'mimetypenames.json',
			[PadFileType::MIME => self::MIME_NAME],
			'file-type name',
		) && $complete;
		$complete = $this->ensureCoreFiletypeIcon($output) && $complete;

		$output->info($complete
			? 'Registered .pad files and backfilled their MIME type.'
			: 'Registered .pad files, but some of it was skipped - see the warnings above.');
	}

	/** @return bool whether the icon is in place */
	private function ensureCoreFiletypeIcon(IOutput $output): bool {
		if (!isset(\OC::$SERVERROOT) || !is_string(\OC::$SERVERROOT) || \OC::$SERVERROOT === '') {
			$output->warning('Could not synchronize the Etherpad file-type icon: the server root is unavailable.');
			return false;
		}

		try {
			$appPath = $this->appManager->getAppPath(Application::APP_ID);
		} catch (AppPathNotFoundException) {
			$output->warning('Could not synchronize the Etherpad file-type icon: the app path is unavailable.');
			return false;
		}

		$serverRoot = rtrim(\OC::$SERVERROOT, '/');
		$appIcon = rtrim($appPath, '/') . '/' . self::APP_ICON_RELATIVE;
		$coreIconDir = $serverRoot . '/' . self::CORE_ICON_DIR;
		$coreIcon = $coreIconDir . '/' . self::MIME_ALIAS . '.svg';

		if (!is_file($appIcon)) {
			$output->warning('Could not synchronize the Etherpad file-type icon: the app icon is missing.');
			return false;
		}

		$iconContents = @file_get_contents($appIcon);
		if (!is_string($iconContents) || $iconContents === '') {
			$output->warning('Could not synchronize the Etherpad file-type icon: the app icon is empty or unreadable.');
			return false;
		}

		if (is_file($coreIcon) || is_link($coreIcon)) {
			$existing = @file_get_contents($coreIcon);
			if (is_string($existing) && $existing === $iconContents) {
				return true;
			}
		}

		if (!is_dir($coreIconDir) || !is_writable($coreIconDir)) {
			$output->warning('Could not synchronize the Etherpad file-type icon: the core icon directory is not writable.');
			return false;
		}

		if (!$this->writeAtomically($coreIcon, $iconContents)) {
			$output->warning('Could not synchronize the Etherpad file-type icon.');
			return false;
		}

		$output->info('Synchronized the Etherpad file-type icon into core.');

		return true;
	}

	/**
	 * Write through a sibling temporary file.
	 *
	 * The targets are shared with other apps and with the server itself, and
	 * a plain write truncates first: an interrupted one leaves the file empty
	 * and takes every unrelated entry in it with it.
	 */
	private function writeAtomically(string $file, string $contents): bool {
		$temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
		if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
			return false;
		}

		if (!@rename($temporary, $file)) {
			@unlink($temporary);

			return false;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $mappings
	 * @return bool whether the mapping is in place
	 */
	private function appendOptionalMappings(
		IOutput $output,
		string $file,
		array $mappings,
		string $purpose,
	): bool {
		try {
			$this->appendToJsonFile($file, $mappings);
		} catch (\RuntimeException $e) {
			$output->warning(sprintf(
				'Could not register the optional Etherpad %s: %s',
				$purpose,
				$e->getMessage(),
			));

			return false;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $mappings
	 */
	private function appendToJsonFile(string $file, array $mappings): void {
		$current = $this->readJsonFile($file);
		if (self::containsMappings($current, $mappings)) {
			return;
		}

		$updated = array_replace($current, $mappings);
		try {
			$encoded = json_encode(
				$updated,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
			);
		} catch (\JsonException $e) {
			throw new \RuntimeException(
				sprintf('Could not encode MIME configuration file "%s".', $file),
				0,
				$e,
			);
		}

		if (!$this->writeAtomically($file, $encoded . "\n")) {
			throw new \RuntimeException(sprintf(
				'Could not write MIME configuration file "%s". Make the Nextcloud config directory writable and try again.',
				$file,
			));
		}

		if (!self::containsMappings($this->readJsonFile($file), $mappings)) {
			throw new \RuntimeException(sprintf(
				'MIME configuration file "%s" was written but does not contain the required entry.',
				$file,
			));
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function readJsonFile(string $file): array {
		if (!file_exists($file)) {
			return [];
		}

		$contents = @file_get_contents($file);
		if (!is_string($contents)) {
			throw new \RuntimeException(sprintf(
				'Could not read MIME configuration file "%s".',
				$file,
			));
		}

		// Nothing in it is nothing mapped. An interrupted write leaves this
		// shape, and refusing it would make the file permanently unusable.
		if (trim($contents) === '') {
			return [];
		}

		try {
			$decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \RuntimeException(
				sprintf('MIME configuration file "%s" contains invalid JSON.', $file),
				0,
				$e,
			);
		}

		// An empty file decodes the same either way and means no mappings;
		// anything else that decodes to a list cannot hold them.
		if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
			throw new \RuntimeException(sprintf(
				'MIME configuration file "%s" must contain a JSON object.',
				$file,
			));
		}

		return $decoded;
	}

	/**
	 * @param array<string,mixed> $current
	 * @param array<string,mixed> $mappings
	 */
	private static function containsMappings(array $current, array $mappings): bool {
		foreach ($mappings as $key => $value) {
			if (!array_key_exists($key, $current) || $current[$key] !== $value) {
				return false;
			}
		}

		return true;
	}
}
