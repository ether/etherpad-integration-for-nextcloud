<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Util\SafeError;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 * @psalm-api
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
					'fileId' => (int)$node->getId(),
					'reason' => (string)($result['reason'] ?? 'unknown'),
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('MoveToTrash listener aborted due to lifecycle error', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$node->getId(),
				...SafeError::context($e),
			]);
			throw $e;
		}
	}
}
