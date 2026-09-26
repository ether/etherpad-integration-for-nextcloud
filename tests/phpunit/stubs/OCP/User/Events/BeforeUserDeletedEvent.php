<?php

declare(strict_types=1);

namespace OCP\User\Events;

use OCP\EventDispatcher\Event;
use OCP\IUser;

if (!class_exists(BeforeUserDeletedEvent::class)) {
	class BeforeUserDeletedEvent extends Event {
		public function __construct(private IUser $user) {
		}

		public function getUser(): IUser {
			return $this->user;
		}
	}
}
