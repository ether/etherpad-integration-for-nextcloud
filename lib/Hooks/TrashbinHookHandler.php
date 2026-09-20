<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Hooks;

use OCA\EtherpadNextcloud\Util\SafeError;
use OCA\EtherpadNextcloud\Listeners\RestoreFromTrashListener;
use Psr\Log\LoggerInterface;

/**
 * @psalm-api
 */
class TrashbinHookHandler {
	/**
	 * @param array<string,mixed> $params
	 */
	public static function postRestore(array $params): void {
		try {
			\OCP\Server::get(RestoreFromTrashListener::class)->handleLegacyHook($params);
		} catch (\Throwable $e) {
			\OCP\Server::get(LoggerInterface::class)->error('Legacy trashbin restore hook failed.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
			throw $e;
		}
	}
}
