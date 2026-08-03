<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\TemplateExistsException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * The files behind the template tiles and the admin's shared templates, all in
 * one appdata folder — see docs/templates.md for what lives there and why.
 *
 * `IAppData` owns the folder; the nodes come from `IRootFolder`, because
 * Nextcloud's template API needs a real `OCP\Files\File` and the
 * simple-filesystem view cannot give one.
 *
 * @psalm-api
 */
class PadTemplateStorage {
	public const TEMPLATE_DIR = 'templates';

	/**
	 * Names the app keeps for the tiles it offers in the picker itself, and the
	 * marker files behind them. The picker labels a tile with the file's own
	 * name, so a template of the same name would be a second tile nobody can
	 * tell apart — and it is also why these strings cannot be translated.
	 */
	public const PUBLIC_TILE_NAME = 'Public pad.pad';
	public const EXTERNAL_TILE_NAME = 'Public pad from URL.pad';

	/** @return list<string> */
	public static function reservedNames(): array {
		return [self::PUBLIC_TILE_NAME, self::EXTERNAL_TILE_NAME];
	}

	public function __construct(
		private IRootFolder $rootFolder,
		private IAppDataFactory $appDataFactory,
		private ILockingProvider $lockingProvider,
	) {
	}

	public function publicMarker(): File {
		return $this->marker(self::PUBLIC_TILE_NAME);
	}

	public function externalMarker(): File {
		return $this->marker(self::EXTERNAL_TILE_NAME);
	}

	public function isPublicMarkerFile(File $template): bool {
		return $this->isMarkerFile($template, self::PUBLIC_TILE_NAME);
	}

	public function isExternalMarkerFile(File $template): bool {
		return $this->isMarkerFile($template, self::EXTERNAL_TILE_NAME);
	}

	/**
	 * The templates an admin uploaded, in name order.
	 *
	 * @return list<File>
	 */
	public function globalTemplates(): array {
		// Deliberately not swallowing failures: an admin looking at an empty
		// list has no way to tell "none uploaded" from "appdata unreadable".
		// The picker is the one place that tolerates this — see
		// PadTemplateProvider::globalTiles().
		$entries = $this->templateFolder()->getDirectoryListing();

		$files = [];
		foreach ($entries as $entry) {
			if (!$entry instanceof File || !str_ends_with(strtolower($entry->getName()), '.pad')) {
				continue;
			}
			// The markers share this folder and are tiles in their own right.
			if (in_array($entry->getName(), self::reservedNames(), true)) {
				continue;
			}
			$files[] = $entry;
		}
		usort($files, static fn(File $a, File $b): int => strcasecmp($a->getName(), $b->getName()));
		return $files;
	}

	/**
	 * The name arrives from the client, so it is matched against the listing
	 * rather than passed to get(): that keeps a crafted value from reaching
	 * anything outside this folder, and keeps the markers out of reach.
	 */
	public function globalTemplate(string $name): ?File {
		foreach ($this->globalTemplates() as $file) {
			if ($file->getName() === $name) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Expects a name that has already been validated — see
	 * PadTemplateAdminService::validateName(). The read paths match against the
	 * listing, so a crafted name gets them nowhere; this one hands it to get()
	 * and newFile(), and that check is what keeps it inside this folder.
	 *
	 * Whether an existing file may be overwritten is decided here, not by the
	 * caller beforehand: Folder::newFile() happily writes over what is already
	 * there, so a separate "does it exist?" question would leave a window in
	 * which a second upload creates the file and this one destroys it without
	 * anyone confirming. Check and write therefore run together, under an
	 * exclusive lock on the name — the same locking provider Nextcloud uses
	 * for files, so the exclusion holds across the whole cluster.
	 *
	 * @throws TemplateExistsException when the name is taken and $replace is false
	 */
	public function addGlobalTemplate(string $name, string $content, bool $replace = false): File {
		return $this->withNameLocked($name, function (Folder $folder) use ($name, $content, $replace): File {
			$existing = $this->readFile($folder, $name);
			if ($existing !== null) {
				if (!$replace) {
					throw new TemplateExistsException($name);
				}
				$existing->putContent($content);
				return $existing;
			}
			return $folder->newFile($name, $content);
		});
	}

	/** Deleting takes the same lock as writing, so the two cannot interleave. */
	public function deleteGlobalTemplate(string $name): bool {
		return $this->withNameLocked($name, function () use ($name): bool {
			$file = $this->globalTemplate($name);
			if ($file === null) {
				return false;
			}
			$file->delete();
			return true;
		});
	}

	/**
	 * The pad type a template file stands for, or an empty string when the
	 * file is not the public marker.
	 *
	 * Read-only, and deliberately so: this runs while another file is being
	 * created, and resolving the marker through publicMarker() could write — a
	 * marker recreated with a fresh id would then fail to match, the empty
	 * marker would be copied over the new pad as if it were a user template,
	 * and the chosen type would be silently lost. Matching on the path cannot
	 * do that, and it also keeps a user's own file of the same name apart.
	 */
	public function accessModeForTemplateFile(File $template): string {
		return $this->isPublicMarkerFile($template) ? BindingService::ACCESS_PUBLIC : '';
	}

	/**
	 * @template T
	 * @param callable(Folder): T $write
	 * @return T
	 */
	private function withNameLocked(string $name, callable $write) {
		$folder = $this->templateFolder();
		$lock = $this->lockKey($folder, $name);

		try {
			$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException $e) {
			// Someone else is writing this very name right now. Whatever they
			// are writing, this request was not told it may replace it.
			throw new TemplateExistsException($name, $e);
		}

		try {
			return $write($folder);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** The marker file for a tile, created on first use. */
	private function marker(string $name): File {
		$folder = $this->templateFolder();
		$existing = $this->readFile($folder, $name);
		if ($existing !== null) {
			return $existing;
		}

		try {
			return $folder->newFile($name, '');
		} catch (\Throwable $e) {
			// Another request may have created it in the meantime, so losing
			// that race is not an error. Anything else — no permission, no
			// space — must keep its own exception.
			$created = $this->readFile($folder, $name);
			if ($created !== null) {
				return $created;
			}
			throw $e;
		}
	}

	private function isMarkerFile(File $template, string $name): bool {
		try {
			$expected = trim($this->templateFolder()->getPath(), '/') . '/' . $name;
		} catch (\Throwable) {
			// Without a readable appdata folder nothing can be our marker, and
			// this runs inside file creation — no place to fail.
			return false;
		}
		return trim($template->getPath(), '/') === $expected;
	}

	/**
	 * Nextcloud's database locking stores this in a 64-character column, and
	 * the plain path already exceeds that with an ordinary instance id and file
	 * name — the write would fail on any instance not backed by Redis. Hashing
	 * keeps it bounded while staying specific to this folder and name.
	 *
	 * 18 characters for the app id, 5 for ':tpl:' and 32 of hash make 55, so a
	 * longer prefix later still fits. 128 bits are ample for telling apart file
	 * names in a single folder; this is a lock key, not a signature.
	 *
	 * The namespace also keeps it clear of Nextcloud's own `files/<hash>`
	 * locks. That cuts both ways: it coordinates this app's writers with each
	 * other, not with anything else reaching into appdata.
	 */
	private function lockKey(Folder $folder, string $name): string {
		return Application::APP_ID . ':tpl:' . substr(sha1($folder->getPath() . "\0" . $name), 0, 32);
	}

	/**
	 * A missing file is an answer; anything else is a fault.
	 *
	 * Translating every failure into "not there" would let a permission or
	 * storage error look like a free name, and the write that follows would
	 * report the wrong problem.
	 */
	private function readFile(Folder $folder, string $name): ?File {
		try {
			$node = $folder->get($name);
		} catch (NotFoundException) {
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	/**
	 * @throws \RuntimeException when appdata cannot be resolved as a folder
	 */
	private function templateFolder(): Folder {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			$appData->getFolder(self::TEMPLATE_DIR);
		} catch (NotFoundException) {
			try {
				$appData->newFolder(self::TEMPLATE_DIR);
			} catch (\Throwable $e) {
				// Another request creating it first is not a failure. Anything
				// else — no permission, no space — keeps its own exception: a
				// follow-up "not found" would say far less about what happened.
				try {
					$appData->getFolder(self::TEMPLATE_DIR);
				} catch (\Throwable) {
					throw $e;
				}
			}
		}

		$path = $this->rootFolder->getAppDataDirectoryName()
			. '/' . Application::APP_ID
			. '/' . self::TEMPLATE_DIR;
		$node = $this->rootFolder->get($path);
		if (!$node instanceof Folder) {
			throw new \RuntimeException('Expected a folder at ' . $path);
		}
		return $node;
	}
}
