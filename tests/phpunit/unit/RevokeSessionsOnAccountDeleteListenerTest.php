<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RevokeSessionsOnAccountDeleteListener;
use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An account deleted without a logout kept its Etherpad sessions, and the
 * pads shared with it, until they expired. They go as the delete starts,
 * while Nextcloud still has the account's Etherpad author.
 */
class RevokeSessionsOnAccountDeleteListenerTest extends TestCase {
	public function testTakesTheSessionsAsTheDeleteStarts(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects(self::once())->method('revokeAll')->with('alice')->willReturn(2);

		$this->listener($revoker)->handle(new BeforeUserDeletedEvent($this->user('alice')));
	}

	/** Once the account is gone, so is its author: nothing is asked then. */
	public function testIgnoresAnyOtherEvent(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects(self::never())->method('revokeAll');

		$this->listener($revoker)->handle(new UserDeletedEvent($this->user('alice')));
		$this->listener($revoker)->handle(new Event());
	}

	/** An account is deleted whatever the pad server says. */
	public function testTheDeleteSurvivesAFailedRevoke(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->method('revokeAll')->willThrowException(new \RuntimeException('etherpad down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		(new RevokeSessionsOnAccountDeleteListener($revoker, $logger))->handle(new BeforeUserDeletedEvent($this->user('alice')));
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function listener(PadSessionRevoker $revoker): RevokeSessionsOnAccountDeleteListener {
		return new RevokeSessionsOnAccountDeleteListener($revoker, $this->createMock(LoggerInterface::class));
	}
}
