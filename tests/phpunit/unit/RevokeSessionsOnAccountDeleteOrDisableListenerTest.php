<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RevokeSessionsOnAccountDeleteOrDisableListener;
use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Delete and disable both take the account's sessions; nothing else does. */
class RevokeSessionsOnAccountDeleteOrDisableListenerTest extends TestCase {
	public function testTakesTheSessionsAsTheDeleteStarts(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects(self::once())->method('revokeAll')->with('alice')->willReturn(2);

		$this->listener($revoker)->handle(new BeforeUserDeletedEvent($this->user('alice')));
	}

	public function testTakesTheSessionsOfADisabledAccount(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects(self::once())->method('revokeAll')->with('alice')->willReturn(2);

		$this->listener($revoker)->handle(new UserChangedEvent($this->user('alice'), 'enabled', false, true));
	}

	/** Once the account is gone, so is its author: nothing is asked then. */
	public function testIgnoresAnyOtherEvent(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects(self::never())->method('revokeAll');
		$listener = $this->listener($revoker);

		$listener->handle(new UserDeletedEvent($this->user('alice')));
		$listener->handle(new Event());
	}

	/** Only `enabled` turning false is a disable, not another feature that does, nor a value other than false. */
	#[\PHPUnit\Framework\Attributes\TestWith(['enabled', true])]
	#[\PHPUnit\Framework\Attributes\TestWith(['displayName', 'Alice'])]
	#[\PHPUnit\Framework\Attributes\TestWith(['quota', false])]
	#[\PHPUnit\Framework\Attributes\TestWith(['enabled', null])]
	public function testTakesNothingForAnyOtherChange(string $feature, mixed $value): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects(self::never())->method('revokeAll');

		$this->listener($revoker)->handle(new UserChangedEvent($this->user('alice'), $feature, $value));
	}

	/** An account is deleted or disabled whatever the pad server says. */
	public function testTheChangeSurvivesAFailedRevoke(): void {
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->method('revokeAll')->willThrowException(new \RuntimeException('etherpad down'));
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged[] = [$message, $context['app'] ?? null, $context['error'] ?? null];
		});
		$listener = new RevokeSessionsOnAccountDeleteOrDisableListener($revoker, $logger);

		$listener->handle(new BeforeUserDeletedEvent($this->user('alice')));
		$listener->handle(new UserChangedEvent($this->user('alice'), 'enabled', false, true));

		$this->assertSame([
			['Could not revoke the Etherpad sessions of an account being deleted; they will expire on their own.', 'etherpad_nextcloud', \RuntimeException::class],
			['Could not revoke the Etherpad sessions of a disabled account; they will expire on their own.', 'etherpad_nextcloud', \RuntimeException::class],
		], $logged);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function listener(PadSessionRevoker $revoker): RevokeSessionsOnAccountDeleteOrDisableListener {
		return new RevokeSessionsOnAccountDeleteOrDisableListener($revoker, $this->createMock(LoggerInterface::class));
	}
}
