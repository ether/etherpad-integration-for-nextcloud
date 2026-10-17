<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUser;

/**
 * Whether a user may use the app. Nextcloud registers an enabled app's
 * listeners and template provider for every user, also where an admin has
 * limited the app to groups, and guards only the app's own routes. So the
 * "New pad" entry, the templates behind it and the create that Nextcloud's
 * template endpoint reports each ask here: the endpoint can be called
 * without the entry.
 *
 * Public shares and background jobs act without a signed-in user and
 * decide their access on their own.
 */
class AppAccess {
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	public function isEnabledFor(?IUser $user): bool {
		return $user !== null && $this->appManager->isEnabledForUser(Application::APP_ID, $user);
	}
}
