<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCP;

/**
 * The service locator, as far as a unit test needs it.
 *
 * Only the legacy trashbin hook reaches for it - a hook slot is a static
 * callable, so there is nowhere to inject. Register what a test wants
 * handed back, or a Throwable to have the lookup fail, and clear it
 * again: this is global state.
 */
if (!class_exists(Server::class, false)) {
	class Server {
		/** @var array<string, object> */
		public static array $registered = [];

		public static function reset(): void {
			self::$registered = [];
		}

		public static function get(string $class): object {
			$value = self::$registered[$class] ?? null;
			if ($value instanceof \Throwable) {
				throw $value;
			}
			if ($value === null) {
				throw new \RuntimeException('Nothing registered for ' . $class);
			}
			return $value;
		}
	}
}
