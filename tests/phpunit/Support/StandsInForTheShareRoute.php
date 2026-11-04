<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCP\IURLGenerator;

/**
 * Nextcloud's router for the share page, as it answers: the prefix it puts
 * before /s/<token>, the token encoded by the router. Any other route
 * fails the test, so a wrong route name cannot pass.
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
