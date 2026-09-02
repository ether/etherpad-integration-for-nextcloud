<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\UserLoggedOutListener;
use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserLoggedOutEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Etherpad session cookie is a separate cookie on a separate host with
 * its own lifetime, and a Nextcloud logout never reached it. On a shared
 * machine the next person's browser still carried it.
 */
class UserLoggedOutListenerTest extends TestCase {
	public function testTakesTheSessionsWithTheLogout(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects(self::once())->method('revokeAll')->with('alice')->willReturn(2);

		$this->listener($revoker)->handle(new UserLoggedOutEvent($this->user('alice')));
	}

	public function testIgnoresAnEventWithoutAUser(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects(self::never())->method('revokeAll');

		$this->listener($revoker)->handle(new UserLoggedOutEvent(null));
	}

	public function testIgnoresAnyOtherEvent(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects(self::never())->method('revokeAll');

		$this->listener($revoker)->handle(new Event());
	}

	/** A logout does not fail because a pad server is unreachable. */
	public function testALogoutSurvivesAFailedRevoke(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->method('revokeAll')->willThrowException(new \RuntimeException('etherpad down'));

		$this->listener($revoker)->handle(new UserLoggedOutEvent($this->user('alice')));
		$this->addToAssertionCount(1);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function listener(PadSessionRevoker $revoker): UserLoggedOutListener {
		return new UserLoggedOutListener($revoker, $this->createMock(LoggerInterface::class));
	}
}
