<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\InvalidPadNameException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\Files\StorageNotAvailableException;
use OCP\Lock\ILockingProvider;
use OCP\IL10N;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Creates the Nextcloud file behind a pad.
 *
 * The serialisation below relies on Nextcloud's locking provider. On an
 * instance with `filelocking.enabled` set to false that provider is a no-op,
 * and creating falls back to check-then-create with the window this class
 * exists to close.
 */
class PadFileCreator {
	public function __construct(
		private IRootFolder $rootFolder,
		private ILockingProvider $lockingProvider,
		private IFilenameValidator $filenameValidator,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws \RuntimeException
	 * @throws PadFileAlreadyExistsException
	 * @throws InvalidPadNameException
	 */
	public function createUserFile(string $uid, string $absolutePath): File {
		$relativePath = ltrim($absolutePath, '/');
		if ($relativePath === '') {
			throw new \RuntimeException('Invalid empty create path.');
		}

		$parentPath = dirname($relativePath);
		$fileName = basename($relativePath);
		// Psalm infers basename() as non-empty-string, but it returns '' for
		// slash-only inputs (e.g. basename('/')), so the empty-string guard is
		// a real defensive check, not dead code.
		/** @psalm-suppress TypeDoesNotContainType */
		if ($fileName === '' || $fileName === '.' || $fileName === '..') {
			throw new \RuntimeException('Invalid target filename.');
		}

		$userFolder = $this->rootFolder->getUserFolder($uid);
		try {
			$parent = $parentPath === '.' ? $userFolder : $userFolder->get($parentPath);
		} catch (NotFoundException $e) {
			throw new \RuntimeException('Target parent folder does not exist.', 0, $e);
		}
		if (!$parent instanceof Folder) {
			throw new \RuntimeException('Target parent folder does not exist.');
		}

		return $this->createUserFileInFolder($parent, $fileName);
	}

	/**
	 * Create the target `.pad`, or refuse because the name is taken.
	 *
	 * Checking and creating are two steps, and between them another request
	 * can take the name. Measured against a real instance, six simultaneous
	 * creates of one name produced six 500s and, twice out of three rounds,
	 * no file at all: the storage refused every one of them, and the retry
	 * inside the catch could not see the winner yet because its cache entry
	 * had not appeared. Nobody got a pad, and nobody got told why.
	 *
	 * So the two steps are serialised on the target name. The loser is
	 * turned away with "that name is taken", which is what actually
	 * happened, instead of a server error.
	 *
	 * @throws \RuntimeException
	 * @throws PadFileAlreadyExistsException
	 * @throws InvalidPadNameException
	 */
	public function createUserFileInFolder(Folder $parent, string $fileName): File {
		$this->requireNameThisFolderAccepts($parent, $fileName);

		$lock = $this->lockKey($parent, $fileName);
		try {
			$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException $e) {
			// Another create holds this exact name right now. Whether it
			// succeeds or not, this request may not have it.
			//
			// Logged, because it is not the same thing as the file being
			// there: a request killed between acquire and release leaves the
			// row behind for up to an hour, and the user then sees "that name
			// is taken" for a folder that is empty. Without this line there
			// is nothing to explain it.
			$this->logger->warning('A pad create was refused because the name is locked by another create', [
				'app' => 'etherpad_nextcloud',
				'file' => $fileName,
				'lock' => $lock,
			]);
			throw new PadFileAlreadyExistsException('Target .pad file already exists.', 0, $e);
		}

		try {
			return $this->createInLockedName($parent, $fileName);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @throws \RuntimeException
	 * @throws PadFileAlreadyExistsException
	 */
	private function createInLockedName(Folder $parent, string $fileName): File {
		if ($parent->nodeExists($fileName)) {
			throw new PadFileAlreadyExistsException('Target .pad file already exists.');
		}

		try {
			$node = $parent->newFile($fileName);
		} catch (InvalidPathException $e) {
			// Some storages only judge a name when the write happens, so
			// asking beforehand cannot be complete. Nextcloud is the
			// authority either way; this just keeps the answer a 400.
			throw $this->refusedName($e, trustMessage: false);
		} catch (\Throwable $e) {
			// Something else created it outside our lock — the Files UI, a
			// sync client, another app.
			if ($parent->nodeExists($fileName)) {
				throw new PadFileAlreadyExistsException('Target .pad file already exists.', 0, $e);
			}
			throw new \RuntimeException('Could not create .pad file.', 0, $e);
		}
		if (!$node instanceof File) {
			throw new \RuntimeException('Could not create .pad file.');
		}

		return $node;
	}

	/**
	 * Ask whether this name may be used *here*.
	 *
	 * Two questions, because they have different answers. The filename
	 * validator knows the instance's rules — configured forbidden characters
	 * and names, control characters. The storage behind this particular
	 * folder can add its own, and an external or mounted folder often does;
	 * its own interface says as much. Asking only the first would still let
	 * a name fail deep in the storage, which is the 500 this exists to
	 * prevent.
	 *
	 * @throws InvalidPadNameException
	 */
	private function requireNameThisFolderAccepts(Folder $parent, string $fileName): void {
		// Two questions with different answers, asked for different reasons.
		//
		// The filename validator knows Nextcloud's own rules, and its
		// refusal carries a translated sentence naming the rule — that one
		// is worth showing the user.
		try {
			$this->filenameValidator->validateFilename($fileName);
		} catch (InvalidPathException $e) {
			throw $this->refusedName($e, trustMessage: true);
		}

		// The storage behind this folder may add rules of its own, and an
		// external or mounted folder often does. Its message is not shown:
		// these exception classes are public, so a storage app is free to
		// put a mount point, a bucket name or a driver error in one, and
		// this reaches a browser.
		try {
			$storage = $parent->getStorage();
			$internalPath = $parent->getInternalPath();
		} catch (StorageNotAvailableException $e) {
			$this->logStorageSilence($fileName, $e);
			return;
		}
		if (!$storage instanceof IStorage) {
			return;
		}

		try {
			$storage->verifyPath($internalPath, $fileName);
		} catch (InvalidPathException $e) {
			throw $this->refusedName($e, trustMessage: false);
		} catch (StorageNotAvailableException $e) {
			// Not a verdict on the name — an unreachable mount. The create
			// goes on and newFile() decides. Anything else that throws here
			// is a defect and is left to surface.
			$this->logStorageSilence($fileName, $e);
		}
	}

	private function logStorageSilence(string $fileName, \Throwable $e): void {
		$this->logger->warning('Could not ask the storage whether it accepts a pad name', [
			'app' => 'etherpad_nextcloud',
			'file' => $fileName,
			'exception' => $e,
		]);
	}

	/**
	 * @param bool $trustMessage whether the exception came from the
	 *        filename validator, whose message is Nextcloud's own and safe
	 *        to show. The exception classes alone do not establish that:
	 *        they are public, and any storage app may throw them.
	 */
	private function refusedName(InvalidPathException $e, bool $trustMessage): InvalidPadNameException {
		$message = trim($e->getMessage());
		return new InvalidPadNameException($trustMessage && $message !== ''
			? $message
			: $this->l10n->t('That file name is not allowed on this server.'), 0, $e);
	}

	/**
	 * Keyed on the folder's file id, not its path.
	 *
	 * A shared folder has a different path for its owner than for everyone
	 * it is shared with, so a path-based key would hand the same target two
	 * different locks — and "two people create the same pad at once" is
	 * exactly the case a shared folder makes likely. The file id is the same
	 * number for all of them.
	 *
	 * `oc_file_locks.key` is varchar(64), hence the hash.
	 */
	private function lockKey(Folder $parent, string $fileName): string {
		return Application::APP_ID . ':create:' . substr(sha1($parent->getId() . "\0" . $fileName), 0, 32);
	}
}
