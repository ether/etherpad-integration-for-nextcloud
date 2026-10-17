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
 * app's own routes, not on the public ones that serve link shares. Code
 * that acts for a user anywhere else asks here.
 */
class AppAccess {
	public function __construct(
		private IAppManager $appManager,
		private IUserSession $userSession,
	) {
	}

	/** True only for a signed-in user the app is enabled for. */
	public function isEnabledForCurrentUser(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->appManager->isEnabledForUser(Application::APP_ID, $user);
	}

	/**
	 * True only for a signed-in user the app is not enabled for. Without a
	 * user nobody is excluded: public shares decide their access on their own.
	 */
	public function excludesCurrentUser(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && !$this->appManager->isEnabledForUser(Application::APP_ID, $user);
	}
}
