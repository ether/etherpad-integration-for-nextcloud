<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCP\IURLGenerator;

/**
 * Stands in for Nextcloud's router on the share route: answers
 * `<prefix>/s/<rawurlencoded token>` and fails the test on any other route
 * name.
 */
trait StandsInForTheShareRoute {
	private function shareRoute(string $prefix = ''): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			function (string $route, array $arguments) use ($prefix): string {
				$this->assertSame('files_sharing.sharecontroller.showShare', $route);
				return $prefix . '/s/' . rawurlencode((string)$arguments['token']);
			},
		);
		return $urlGenerator;
	}
}
