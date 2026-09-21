<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Hooks;

use OCA\EtherpadNextcloud\Listeners\RestoreFromTrashListener;
use OCA\EtherpadNextcloud\Util\SafeError;
use Psr\Log\LoggerInterface;

/**
 * @psalm-api
 */
class TrashbinHookHandler {
	/**
	 * @param array<string,mixed> $params
	 */
	public static function postRestore(array $params): void {
		// Fetched before the one that may fail, so reporting that failure
		// cannot be the second one.
		$logger = \OCP\Server::get(LoggerInterface::class);

		try {
			$listener = \OCP\Server::get(RestoreFromTrashListener::class);
		} catch (\Throwable $e) {
			// OC_Hook::emit swallows what a slot throws and lets the restore
			// go on, and the entry it writes carries no app - so a hook that
			// never started is silent unless it says so here.
			$logger->error('Legacy trashbin restore hook could not start.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
			throw $e;
		}

		// Not caught: the listener reports what it could not do, and a
		// second entry here would be that same failure again.
		$listener->handleLegacyHook($params);
	}
}
