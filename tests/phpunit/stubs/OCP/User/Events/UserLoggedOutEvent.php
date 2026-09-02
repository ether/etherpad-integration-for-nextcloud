<?php

declare(strict_types=1);

namespace OCP\User\Events;

use OCP\EventDispatcher\Event;
use OCP\IUser;

if (!class_exists(UserLoggedOutEvent::class)) {
	class UserLoggedOutEvent extends Event {
		public function __construct(private ?IUser $user = null) {
		}

		public function getUser(): ?IUser {
			return $this->user;
		}
	}
}
