<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Tests\Support\AppSource;
use PHPUnit\Framework\TestCase;

/**
 * A controller takes a file or folder id as `mixed` and reads it strictly
 * (ReadsPositiveIds, PositiveIntParam). Typed `int`, Nextcloud casts what
 * the request sent before the method sees it: `1e3` becomes 1000, `7.5`
 * and `7x` become 7, files the address does not name. Left untyped, it
 * casts by the `@param` of the docblock instead, so the type is written
 * out.
 */
class ControllerIdParametersTest extends TestCase {
	public function testEveryRouteTakesAFileOrFolderIdAsMixed(): void {
		$actions = [];
		foreach (self::routes() as $route) {
			[$controller, $method] = explode('#', $route['name']);
			$actions['OCA\\EtherpadNextcloud\\Controller\\' . ucfirst($controller) . 'Controller::' . $method] = true;
		}
		$found = [];
		$typed = [];
		$seen = 0;
		foreach (AppSource::methods() as [$class, $method, $parameters]) {
			$action = $class . '::' . $method;
			if (!isset($actions[$action])) {
				continue;
			}
			$found[$action] = true;
			foreach ($parameters as [$type, $name]) {
				if (preg_match('/(^|[a-z])(file|folder|File|Folder)Id$/', $name) !== 1) {
					continue;
				}
				$seen++;
				if (strtolower($type) !== 'mixed') {
					$typed[] = $action . '(' . ($type === '' ? 'untyped' : $type) . ' $' . $name . ')';
				}
			}
		}

		// A route whose method this cannot see - from a trait or a parent -
		// would be passed over without a word.
		$this->assertSame([], array_keys(array_diff_key($actions, $found)), 'routes whose method was not found');
		$this->assertGreaterThan(10, $seen, 'the ids the routes take');
		$this->assertSame([], $typed, 'cast by Nextcloud before it is read');
	}

	/** @return list<array{name: string}> */
	private static function routes(): array {
		/** @var array{routes: list<array{name: string}>} $routes */
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		return $routes['routes'];
	}
}
