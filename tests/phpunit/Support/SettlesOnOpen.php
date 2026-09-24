<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\RestoreService;
use OCA\EtherpadNextcloud\Service\SettleLock;
use OCA\EtherpadNextcloud\Service\SettleOnOpen;
use OCA\EtherpadNextcloud\Service\SettleOutcome;
use Psr\Log\LoggerInterface;

/**
 * The open's check of a file's row over $bindings, as the container builds
 * it: a row that waits is decided by $restores, under a lock of its own -
 * unless the test gives one, a mock whose decision leaves Etherpad silent,
 * as an enum cannot be doubled.
 */
trait SettlesOnOpen {
	private function settleOnOpen(BindingService $bindings, ?RestoreService $restores = null): SettleOnOpen {
		$logger = $this->createMock(LoggerInterface::class);
		return new SettleOnOpen(
			$bindings,
			$restores ?? $this->silentRestores(),
			new SettleLock(new InMemoryLockingProvider(), $logger),
			new FixedClock(),
			$logger,
		);
	}

	private function silentRestores(): RestoreService {
		$restores = $this->createMock(RestoreService::class);
		$restores->method('settleOpenedFile')->willReturn(SettleOutcome::Unanswered);
		return $restores;
	}
}
