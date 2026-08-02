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
 * The templates an admin shares with everyone, in the app's appdata folder —
 * see docs/templates.md for what lives there and why.
 *
 * `IAppData` owns the folder; the nodes come from `IRootFolder`, because
 * Nextcloud's template API needs a real `OCP\Files\File` and the
 * simple-filesystem view cannot give one.
 *
 * @psalm-api
 */
class PadTemplateStorage {
	public const TEMPLATE_DIR = 'templates';

	public function __construct(
		private IRootFolder $rootFolder,
		private IAppDataFactory $appDataFactory,
		private ILockingProvider $lockingProvider,
	) {
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
			$files[] = $entry;
		}
		usort($files, static fn(File $a, File $b): int => strcasecmp($a->getName(), $b->getName()));
		return $files;
	}

	/**
	 * The name arrives from the client, so it is matched against the listing
	 * rather than passed to get(): that keeps a crafted value from reaching
	 * anything outside this folder.
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
	 * @template T
	 * @param callable(Folder): T $write
	 * @return T
	 */
	private function withNameLocked(string $name, callable $write) {
		$folder = $this->templateFolder();
		$lock = $folder->getPath() . '/' . $name;

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
