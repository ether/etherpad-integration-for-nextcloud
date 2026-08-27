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
	 *
	 * Returning null means the restore goes ahead untouched and our
	 * bookkeeping is skipped, so every way out of here says why.
	 */
	private function materialize(File $node): ?File {
		if ($this->hasReadableId($node)) {
			return $node;
		}

		try {
			$path = $node->getPath();
		} catch (\Throwable $e) {
			$this->logSkip('the restored node has no readable path', null, $e);
			return null;
		}

		$parts = explode('/', ltrim($path, '/'), 3);
		if (count($parts) !== 3 || $parts[1] !== 'files' || $parts[0] === '' || $parts[2] === '') {
			$this->logSkip('the restored node\'s path is not /<user>/files/<path>', $path);
			return null;
		}

		try {
			$resolved = $this->rootFolder->getUserFolder($parts[0])->get($parts[2]);
		} catch (NotFoundException $e) {
			$this->logSkip('the restored node was not found at its own path', $path, $e);
			return null;
		} catch (\Throwable $e) {
			$this->logSkip('the restored node could not be resolved', $path, $e);
			return null;
		}

		if (!$resolved instanceof File) {
			$this->logSkip('the restored path does not point at a file', $path);
			return null;
		}

		// Hold the replacement to the same standard as the node we rejected:
		// handleRestore() reads the id on its first line, so handing on one
		// that still throws would put the abort straight back.
		if (!$this->hasReadableId($resolved)) {
			$this->logSkip('the re-resolved node still has no readable id', $path);
			return null;
		}

		return $resolved;
	}

	private function hasReadableId(File $node): bool {
		try {
			$node->getId();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function logSkip(string $reason, ?string $path, ?\Throwable $e = null): void {
		$context = ['app' => 'etherpad_nextcloud', 'reason' => $reason];
		if ($path !== null) {
			$context['filePath'] = $path;
		}
		if ($e !== null) {
			$context['exception'] = $e;
		}
		$this->logger->warning('RestoreFromTrash listener skipped a restored node.', $context);
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
