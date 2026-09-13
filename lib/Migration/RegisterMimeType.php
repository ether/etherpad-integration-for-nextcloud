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
	/**
	 * A text document, not an office one: aliasing onto x-office/document
	 * would also file pads under the Files type filter's "Documents", and
	 * Nextcloud does not put its own Markdown there either (#27).
	 */
	private const MIME_ALIAS = 'text';
	/** What earlier versions of this app copied into core. */
	private const LEGACY_ICON_NAME = 'etherpad-nextcloud-pad';
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
		$complete = $this->removeLegacyCoreIcon($output) && $complete;

		$output->info($complete
			? 'Registered .pad files and backfilled their MIME type.'
			: 'Registered .pad files, but some of it was skipped - see the warnings above.');
	}

	/**
	 * Take back the icon earlier versions copied into core.
	 *
	 * Core is signed: a file an app adds there is reported as an extra file
	 * by the integrity check and is lost on the next server upgrade. An icon
	 * that is not the one this app wrote belongs to whoever put it there.
	 *
	 * @return bool whether core is clean
	 */
	private function removeLegacyCoreIcon(IOutput $output): bool {
		if (!isset(\OC::$SERVERROOT) || !is_string(\OC::$SERVERROOT) || \OC::$SERVERROOT === '') {
			return true;
		}

		$coreIcon = rtrim(\OC::$SERVERROOT, '/') . '/' . self::CORE_ICON_DIR
			. '/' . self::LEGACY_ICON_NAME . '.svg';
		if (!is_file($coreIcon) && !is_link($coreIcon)) {
			return true;
		}

		try {
			$appIcon = rtrim($this->appManager->getAppPath(Application::APP_ID), '/')
				. '/' . self::APP_ICON_RELATIVE;
		} catch (AppPathNotFoundException) {
			$appIcon = '';
		}

		$ours = $appIcon !== '' && is_file($appIcon)
			&& @file_get_contents($coreIcon) === @file_get_contents($appIcon);
		if (!$ours) {
			$output->warning(sprintf(
				'Left %s in place: it is not the icon this app installed.',
				$coreIcon,
			));

			return false;
		}

		if (!@unlink($coreIcon)) {
			$output->warning(sprintf('Could not remove %s, which this app should no longer install.', $coreIcon));

			return false;
		}

		$output->info('Removed the Etherpad file-type icon this app used to copy into core.');

		return true;
	}

	/**
	 * Write through a sibling temporary file.
	 *
	 * The target is shared with other apps and with the server itself, and a
	 * plain write truncates first: an interrupted one leaves the file empty
	 * and takes every unrelated entry in it along. This cannot stop another
	 * writer from overwriting our entry - it only rules out a half-written
	 * file. The replacement carries the mode, owner and group the target had,
	 * which rename() would otherwise replace with the temporary file's.
	 */
	private function writeAtomically(string $file, string $contents): bool {
		$temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
		if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
			return false;
		}

		$mode = @fileperms($file);
		if ($mode !== false) {
			@chmod($temporary, $mode & 07777);
			$owner = @fileowner($file);
			$group = @filegroup($file);
			if ($owner !== false) {
				@chown($temporary, $owner);
			}
			if ($group !== false) {
				@chgrp($temporary, $group);
			}
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
