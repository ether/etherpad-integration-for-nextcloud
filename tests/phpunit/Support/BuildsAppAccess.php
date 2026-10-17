<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\AppAccess;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * AppAccess for a signed-in user the app is enabled for or not, or for a
 * request without one. The app manager answers only for the app's id and
 * that user, so a check that asks about another app or user is refused.
 */
trait BuildsAppAccess {
	/** @param ?bool $enabledForUser null for a request without a signed-in user */
	private function appAccess(?bool $enabledForUser): AppAccess {
		$user = $enabledForUser === null ? null : $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $appId, ?IUser $asked = null): bool => $appId === Application::APP_ID && $asked === $user && $enabledForUser === true
		);
		return new AppAccess($appManager, $userSession);
	}
}
