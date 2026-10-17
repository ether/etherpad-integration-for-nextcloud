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
	 * Asked without a user, Nextcloud answers by the app's setting alone:
	 * yes where it is enabled for everyone, which would offer a link share's
	 * visitor "New pad", and no where it is limited to groups, which would
	 * count a request without a user as excluded.
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
