<?php

declare(strict_types=1);

namespace OCP\EventDispatcher;

if (!class_exists(GenericEvent::class)) {
	class GenericEvent extends Event {
		/** @param array<string,mixed> $arguments */
		public function __construct(
			private mixed $subject = null,
			private array $arguments = [],
		) {
		}

		public function getSubject(): mixed {
			return $this->subject;
		}

		public function hasArgument(string $key): bool {
			return array_key_exists($key, $this->arguments);
		}

		public function getArgument(string $key): mixed {
			if (!$this->hasArgument($key)) {
				throw new \InvalidArgumentException('Argument not found: ' . $key);
			}

			return $this->arguments[$key];
		}
	}
}
