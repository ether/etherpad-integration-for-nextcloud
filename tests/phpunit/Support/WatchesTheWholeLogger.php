<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * "Exactly one entry" is a claim about the logger, not about error().
 * A repeat added at another level would satisfy an expectation that only
 * watches one method, so the rest of the surface is closed by name -
 * including log(), which takes the level as an argument.
 */
trait WatchesTheWholeLogger {
	private const LOGGER_SURFACE = [
		'emergency', 'alert', 'critical', 'error',
		'warning', 'notice', 'info', 'debug', 'log',
	];

	private function closeEveryLevelExcept(LoggerInterface&MockObject $logger, string $kept): void {
		foreach (self::LOGGER_SURFACE as $level) {
			if ($level !== $kept) {
				$logger->expects($this->never())->method($level);
			}
		}
	}
}
