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

/**
 * @psalm-api
 */
class TrashbinHookHandler {
	/**
	 * @param array<string,mixed> $params
	 */
	public static function postRestore(array $params): void {
		// No catch: the listener reports what it could not do, and a second
		// entry here would be that same failure again. What is left to fail
		// in this frame is the lookup, which is Nextcloud's own and is
		// reported by whoever could not answer it.
		\OCP\Server::get(RestoreFromTrashListener::class)->handleLegacyHook($params);
	}
}
