<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Registers .pad files: their mapping, alias and name in the config
 * directory, as the admin manual has it for a MIME type of one's own
 * (https://docs.nextcloud.com/server/stable/admin_manual/configuration_mimetypes/index.html),
 * and their type on the files the file cache has already.
 *
 * It runs on every enable of the app (install), and after every update and,
 * unless the app is limited to groups, every `occ maintenance:repair`
 * (post-migration; see
 * https://docs.nextcloud.com/server/stable/developer_manual/digging_deeper/repair.html),
 * and the repair of the file cache reads all of it. So the repair runs
 * until it has once finished with the mapping in place, keeping that
 * version as Nextcloud's own RepairMimeTypes does, and again for a newer
 * version or where the mapping was missing.
 *
 * @psalm-api
 */
class RegisterMimeType implements IRepairStep {
	/**
	 * Raised when the repair of the file cache changes, so that it runs once more.
	 *
	 * @var positive-int
	 */
	public const REPAIR_VERSION = 1;

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
		private IDBConnection $db,
		private AppConfigService $appConfig,
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
		$mappingFile = $configDir . 'mimetypemapping.json';
		$mapping = [PadFileType::EXTENSION => [PadFileType::MIME]];

		// The mapping goes first, so that the run that writes it can record
		// the repair; a mapping that cannot be written still lets the repair
		// run, and is reported once it has.
		$mappedBefore = $this->isMapped($mappingFile, $mapping);
		$mappingError = null;
		try {
			$this->writeMapping($mappingFile, $mapping, $mappedBefore);
		} catch (\RuntimeException $e) {
			$mappingError = $e;
		}

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

		$this->repairFileCache($output, $mappedBefore, $mappingError === null);
		if ($mappingError !== null) {
			throw $mappingError;
		}

		$output->info($complete
			? 'Registered .pad files.'
			: 'Registered .pad files, but some of it was skipped - see the warnings above.');
	}

	/**
	 * Where the mapping was missing, files came in untyped, so the version
	 * is forgotten before the mapping is written: the mapping beside a
	 * version still recorded would read as a finished repair, also after
	 * this run's had failed.
	 *
	 * @param array<string,mixed> $mapping
	 * @throws \RuntimeException where either fails
	 */
	private function writeMapping(string $file, array $mapping, bool $mappedBefore): void {
		if (!$mappedBefore) {
			try {
				$this->appConfig->forgetPadFilesMimeVersion();
			} catch (\Exception $e) {
				throw new \RuntimeException(sprintf(
					'Could not delete the app config value %s, so the .pad mapping was not written; the next run tries again: %s',
					AppConfigService::PAD_FILES_MIME_VERSION,
					$e->getMessage(),
				), 0, $e);
			}
		}
		$this->appendToJsonFile($file, $mapping);
	}

	/**
	 * The repair of the file cache, recorded once the mapping was in place
	 * for it. A failure is a warning and the registration stands. Where the
	 * version cannot be read, the file cache is scanned: typing the files
	 * matters more than sparing a scan.
	 */
	private function repairFileCache(IOutput $output, bool $mappedBefore, bool $mappedNow): void {
		if ($mappedBefore) {
			try {
				if ($this->appConfig->padFilesMimeVersion() >= static::REPAIR_VERSION) {
					$output->info(sprintf(
						'The .pad files were typed already; the file cache was not scanned. To scan it again, delete the app config value %s and run the repair - for an app limited to groups, enable it for them again.',
						AppConfigService::PAD_FILES_MIME_VERSION,
					));
					return;
				}
			} catch (\Exception $e) {
				$output->warning(sprintf(
					'Could not read the app config value %s, so the file cache is scanned; should this repeat, delete the value: %s',
					AppConfigService::PAD_FILES_MIME_VERSION,
					$e->getMessage(),
				));
			}
		}

		try {
			$typed = $this->typePadFiles();
		} catch (\Exception $e) {
			$output->warning('Could not give the .pad files their MIME type; the next run tries again: ' . $e->getMessage());
			return;
		}
		$output->info(sprintf('Gave %d .pad files their MIME type.', $typed));

		if (!$mappedNow) {
			return;
		}
		try {
			$this->appConfig->recordPadFilesMimeVersion(static::REPAIR_VERSION);
		} catch (\Exception $e) {
			$output->warning(sprintf(
				'Typed the .pad files, but could not record that in the app config value %s; the next run scans the file cache again: %s',
				AppConfigService::PAD_FILES_MIME_VERSION,
				$e->getMessage(),
			));
		}
	}

	/**
	 * Sets the pad MIME type and its part on every file named *.pad that
	 * lacks either, in one statement: it reads the whole file cache, as no
	 * index serves a suffix.
	 *
	 * @return int the rows it changed
	 */
	private function typePadFiles(): int {
		$typeId = $this->mimeTypeLoader->getId(PadFileType::MIME);
		$partId = $this->mimeTypeLoader->getId(explode('/', PadFileType::MIME, 2)[0]);
		$folderId = $this->mimeTypeLoader->getId('httpd/unix-directory');

		$qb = $this->db->getQueryBuilder();
		$qb->update('filecache')
			// Sharded, the file cache takes a statement without a shard key only so.
			->runAcrossAllShards()
			->set('mimetype', $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT))
			->set('mimepart', $qb->createNamedParameter($partId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->like(
				$qb->func()->lower('name'),
				$qb->createNamedParameter('%' . $this->db->escapeLikeParameter(PadFileType::SUFFIX)),
			))
			->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->neq('mimetype', $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->neq('mimepart', $qb->createNamedParameter($partId, IQueryBuilder::PARAM_INT)),
			));

		return $qb->executeStatement();
	}

	/**
	 * Whether $file maps as $mapping says. One that cannot be read does not;
	 * appendToJsonFile() says what is wrong with it.
	 *
	 * @param array<string,mixed> $mapping
	 */
	private function isMapped(string $file, array $mapping): bool {
		try {
			return self::containsMappings($this->readJsonFile($file), $mapping);
		} catch (\RuntimeException) {
			return false;
		}
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

		// Two failed reads both return false and would compare equal, which
		// would call somebody else's file ours and delete it.
		$installed = @file_get_contents($coreIcon);
		$ours = $installed !== false && $appIcon !== '' && is_file($appIcon)
			&& $installed === @file_get_contents($appIcon);
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
		if (is_link($file)) {
			// Renaming over a link replaces the link. An admin who points one
			// of these at a managed file means the file, so follow it.
			$resolved = @realpath($file);
			if ($resolved === false) {
				return false;
			}
			$file = $resolved;
		}

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
