<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class RestoreFromTrashListener implements IEventListener {
	public function __construct(
		private LifecycleService $lifecycleService,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getTarget')) {
			return;
		}

		$node = $event->getTarget();
		if (!$node instanceof File) {
			return;
		}

		// On Nextcloud 31 the event carries a node that is not resolvable
		// yet, so anything reading its id throws and takes the whole restore
		// down with it — a .pad then cannot be restored from the trash at
		// all, through the web UI, WebDAV or occ alike. Re-resolve it from
		// its path before handing it on.
		$node = $this->materialize($node);
		if ($node === null) {
			return;
		}

		$this->restoreNode($node);
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function handleLegacyHook(array $params): void {
		$filePath = $params['filePath'] ?? null;
		if (!is_string($filePath) || trim($filePath) === '') {
			return;
		}

		$node = $this->resolveUserFileByPath($filePath);
		if (!$node instanceof File) {
			return;
		}

		$this->restoreNode($node);
	}

	private function restoreNode(File $node): void {
		try {
			$result = $this->lifecycleService->handleRestore($node);
			if (($result['status'] ?? '') === LifecycleService::RESULT_SKIPPED) {
				$this->logger->debug('RestoreFromTrash listener skipped lifecycle action.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $this->loggableFileId($node),
					'reason' => (string)($result['reason'] ?? 'unknown'),
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('RestoreFromTrash listener aborted due to lifecycle error', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $this->loggableFileId($node),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * The id for a log line, or null when the node cannot supply one.
	 * Reading it must never throw: this runs inside the catch above, where
	 * a second exception would replace the one being reported and leave no
	 * trace of what actually went wrong.
	 */
	private function loggableFileId(File $node): ?int {
		try {
			return (int)$node->getId();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Return a node whose id can be read, re-resolving it from its path if
	 * the one handed to us cannot. The owner comes from the path rather
	 * than the session, because `occ trashbin:restore` has no session.
	 */
	private function materialize(File $node): ?File {
		try {
			$node->getId();
			return $node;
		} catch (\Throwable) {
			// Not resolvable yet — fall through and look it up by path.
		}

		try {
			$path = $node->getPath();
		} catch (\Throwable) {
			return null;
		}

		$parts = explode('/', ltrim($path, '/'), 3);
		if (count($parts) !== 3 || $parts[1] !== 'files' || $parts[0] === '' || $parts[2] === '') {
			return null;
		}

		try {
			$resolved = $this->rootFolder->getUserFolder($parts[0])->get($parts[2]);
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('RestoreFromTrash listener could not resolve the restored node.', [
				'app' => 'etherpad_nextcloud',
				'filePath' => $path,
				'exception' => $e,
			]);
			return null;
		}

		return $resolved instanceof File ? $resolved : null;
	}

	private function resolveUserFileByPath(string $path): ?File {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$uid = $user->getUID();
		$relativePath = ltrim(trim($path), '/');
		$userFilesPrefix = $uid . '/files/';
		if (str_starts_with($relativePath, $userFilesPrefix)) {
			$relativePath = substr($relativePath, strlen($userFilesPrefix));
		}
		if (str_starts_with($relativePath, 'files/')) {
			$relativePath = substr($relativePath, strlen('files/'));
		}
		if ($relativePath === '') {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->get($relativePath);
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('RestoreFromTrash listener could not resolve legacy restore path.', [
				'app' => 'etherpad_nextcloud',
				'filePath' => $path,
				'exception' => $e,
			]);
			return null;
		}

		return $node instanceof File ? $node : null;
	}
}
