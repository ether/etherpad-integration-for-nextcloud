<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserSession;

/**
 * Whether the signed-in user may use the app. Nextcloud registers an
 * enabled app's listeners and template provider for every user, also where
 * an admin has limited the app to groups, and checks the limit only on the
 * app's own routes, not on the public ones that serve link shares. So what
 * acts for a user outside those routes asks here: the "New pad" entry and
 * its templates, the create Nextcloud's template endpoint reports, and a
 * restore from the trash. The endpoint can be called without the entry, so
 * each of them asks.
 */
class AppAccess {
	public function __construct(
		private IAppManager $appManager,
		private IUserSession $userSession,
	) {
	}

	/** Before there is a file: without a signed-in user, the app is not enabled. */
	public function isEnabledForCurrentUser(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->appManager->isEnabledForUser(Application::APP_ID, $user);
	}

	/**
	 * For acting on a file: a signed-in user the app is not enabled for.
	 * False without one: a background job acts for nobody, and public
	 * shares decide their access on their own.
	 */
	public function excludesCurrentUser(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && !$this->appManager->isEnabledForUser(Application::APP_ID, $user);
	}
}
