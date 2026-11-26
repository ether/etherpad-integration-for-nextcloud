<?php

declare(strict_types=1);

namespace OCP\User\Events;

use OCP\EventDispatcher\Event;
use OCP\IUser;

if (!class_exists(UserChangedEvent::class)) {
	class UserChangedEvent extends Event {
		public function __construct(
			private IUser $user,
			private string $feature,
			private mixed $value,
			private mixed $oldValue = null,
		) {
		}

		public function getUser(): IUser {
			return $this->user;
		}

		public function getFeature(): string {
			return $this->feature;
		}

		public function getValue(): mixed {
			return $this->value;
		}

		public function getOldValue(): mixed {
			return $this->oldValue;
		}
	}
}
