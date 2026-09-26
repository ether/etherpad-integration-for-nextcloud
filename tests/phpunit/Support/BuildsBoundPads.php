<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\BoundPadResolver;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileService;
use Psr\Log\LoggerInterface;

/**
 * The real BoundPadResolver over whatever rows a test gives it, so a test
 * holds the service to the rule rather than to a stubbed answer.
 */
trait BuildsBoundPads {
	/** Where the resolver's Etherpad serves $padId. A method: a trait has constants only from PHP 8.2. */
	private static function padUrlOf(string $padId): string {
		return 'https://pad.example.test/p/' . $padId;
	}

	private function boundPads(BindingService $bindings, ?LoggerInterface $logger = null, ?PadFileService $padFiles = null): BoundPadResolver {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('buildPadUrl')->willReturnCallback(static fn (string $padId): string => self::padUrlOf($padId));
		return new BoundPadResolver(
			$bindings,
			$padFiles ?? new PadFileService(new FixedClock()),
			$etherpad,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
