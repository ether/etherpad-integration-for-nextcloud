<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AppAccess;
use OCP\App\IAppManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AppAccessTest extends TestCase {
	/**
	 * Nextcloud answers true for an app enabled for everyone even without a
	 * user, so a request without one - a visitor of a link share - must not
	 * reach the app manager: it would be offered "New pad".
	 */
	public function testAsksNothingWithoutASignedInUser(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->never())->method('isEnabledForUser');
		$access = new AppAccess($appManager, $userSession);

		$this->assertFalse($access->isEnabledForCurrentUser());
		$this->assertFalse($access->excludesCurrentUser());
	}
}
