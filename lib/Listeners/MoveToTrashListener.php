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
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class MoveToTrashListener implements IEventListener {
	public function __construct(
		private LifecycleService $lifecycleService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getNode')) {
			return;
		}

		$node = $event->getNode();
		if (!$node instanceof File) {
			return;
		}

		try {
			$result = $this->lifecycleService->handleTrash($node);
			if (($result['status'] ?? '') === LifecycleService::RESULT_SKIPPED) {
				$this->logger->debug('MoveToTrash listener skipped lifecycle action.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $this->loggableFileId($node),
					'reason' => (string)($result['reason'] ?? 'unknown'),
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('MoveToTrash listener aborted due to lifecycle error', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $this->loggableFileId($node),
				...SafeError::context($e),
			]);
			throw $e;
		}
	}

	/**
	 * The id for a log line, or null when the node cannot supply one.
	 * Reading it is one of the ways handleTrash fails, so reading it again
	 * to report that failure must not throw a second exception over the
	 * one being reported.
	 */
	private function loggableFileId(File $node): ?int {
		try {
			return (int)$node->getId();
		} catch (\Throwable) {
			return null;
		}
	}
}
