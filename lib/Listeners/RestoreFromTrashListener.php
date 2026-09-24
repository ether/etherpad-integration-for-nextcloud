<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\LifecycleResult;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class RestoreFromTrashListener implements IEventListener {
	public function __construct(
		private LifecycleService $lifecycleService,
		private IUserSession $userSession,
		private UserNodeResolver $userNodeResolver,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getTarget')) {
			return;
		}

		$node = $event->getTarget();
		if (!$node instanceof File || !$this->mayBeAPad($node)) {
			return;
		}

		// On Nextcloud 31 the event carries a node that is not resolvable
		// yet, so anything reading its id throws: every .pad restored through
		// the event would get no pad back, through the web UI, WebDAV or occ
		// alike. Re-resolve it from its path before handing it on.
		$node = $this->materialize($node);
		if ($node === null) {
			return;
		}

		$this->restoreNode($node, 'event');
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
		if ($node === null) {
			return;
		}

		$this->restoreNode($node, 'hook');
	}

	/**
	 * The pad's half of a restore. Nextcloud has put the file back before
	 * either way in fires, so a failure here cannot stop the restore: thrown
	 * on, it would only stop what Nextcloud does after the hook and the
	 * event - the file's versions would stay in the trash, and the user be
	 * told of a failed restore that succeeded. It is reported here and goes
	 * no further. What the pad's restore left unfinished waits for the
	 * sweep, or the file offers its own recovery - save after a database
	 * that fails again in the middle of the rollback, which can leave a row
	 * naming a pad the file does not.
	 *
	 * $via names the way in, `hook` or `event`, for the log: a core restore
	 * takes both (Application::register()).
	 */
	private function restoreNode(File $node, string $via): void {
		try {
			$result = $this->lifecycleService->handleRestore($node);
			if (($result['status'] ?? '') === LifecycleResult::SKIPPED) {
				$this->logger->debug('RestoreFromTrash listener skipped lifecycle action.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $this->loggableFileId($node),
					'reason' => (string)($result['reason'] ?? 'unknown'),
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Could not restore the pad of a file back from the trash. The file itself is restored.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $this->loggableFileId($node),
				'via' => $via,
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * Whether the restored node can be a .pad, by its name alone: every
	 * other restored file is passed over before it costs a lookup. A name
	 * that cannot be read lets the node through, for materialize() to judge.
	 */
	private function mayBeAPad(File $node): bool {
		try {
			return PadFileType::isPad($node->getName());
		} catch (\Throwable) {
			return true;
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
	 * than the session: the path says whose file it is, and the session
	 * only says who happens to be signed in.
	 *
	 * Returning null means the restore goes ahead untouched and our
	 * bookkeeping is skipped, so every way out of here says why.
	 */
	private function materialize(File $node): ?File {
		if ($this->idReadError($node) === null) {
			return $node;
		}

		try {
			$path = $node->getPath();
		} catch (\Throwable $e) {
			$this->logSkip('the restored node has no readable path', null, $e);
			return null;
		}

		$owned = UserNodeResolver::splitUserFilesPath($path);
		if ($owned === null) {
			$this->logSkip('the restored node\'s path is not /<user>/files/<path>', $path);
			return null;
		}

		return $this->acceptRestored($owned[0], $owned[1], $path);
	}

	/**
	 * The hook hands over a path already relative to the user's files
	 * root - core and groupfolders both build it that way - so it is
	 * resolved exactly as it arrives. A folder called `files` is a folder
	 * like any other, and a trailing space is part of a name.
	 *
	 * A name that is not a pad is left alone before anything is looked up.
	 * The hook fires for every restored item, folders included, and none of
	 * the others is this app's business.
	 *
	 * The owner has to come from the session here, because unlike the
	 * event's path this one carries no uid. Both ways a restore is driven
	 * provide one: a web or DAV restore runs as the signed-in user, and
	 * `occ trashbin:restore` sets the user before it restores.
	 */
	private function resolveUserFileByPath(string $path): ?File {
		if (!PadFileType::isPad($path)) {
			return null;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			$this->logSkip('no user session to resolve the restored path against', $path);
			return null;
		}

		return $this->acceptRestored($user->getUID(), $path, $path);
	}

	/**
	 * A node handleRestore can take, or a skip that says why - the one
	 * standard both ways in are held to. handleRestore reads the id on its
	 * first line, so a node that cannot answer for one would throw there
	 * instead, and be reported as a failed restore of its pad rather than
	 * passed over with its reason.
	 */
	private function acceptRestored(string $uid, string $relativePath, string $loggedPath): ?File {
		try {
			$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $relativePath);
		} catch (NotFoundException $e) {
			// Not found and not a file are one exception type, so the reason
			// names both; which of the two it was is in the message it carries.
			$this->logSkip('the restored path does not resolve to a file', $loggedPath, $e);
			return null;
		} catch (\Throwable $e) {
			$this->logSkip('the restored path could not be resolved', $loggedPath, $e);
			return null;
		}

		$unreadable = $this->idReadError($node);
		if ($unreadable !== null) {
			$this->logSkip('the node at the restored path has no readable id', $loggedPath, $unreadable);
			return null;
		}

		return $node;
	}

	/** Why the node's id cannot be read, or null when it can. */
	private function idReadError(File $node): ?\Throwable {
		try {
			$node->getId();
			return null;
		} catch (\Throwable $e) {
			return $e;
		}
	}

	private function logSkip(string $reason, ?string $path, ?\Throwable $e = null): void {
		$context = ['app' => 'etherpad_nextcloud', 'reason' => $reason];
		if ($path !== null) {
			$context['filePath'] = $path;
		}
		if ($e !== null) {
			$context = array_merge($context, SafeError::context($e));
		}
		$this->logger->warning('RestoreFromTrash listener skipped a restored node.', $context);
	}
}
