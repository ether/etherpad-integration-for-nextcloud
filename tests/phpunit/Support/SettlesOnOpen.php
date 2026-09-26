<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\Service\Binding;
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
	use BuildsBoundPads;

	private function settleOnOpen(BindingService $bindings, ?RestoreService $restores = null, ?LoggerInterface $logger = null): SettleOnOpen {
		$logger ??= $this->createMock(LoggerInterface::class);
		return new SettleOnOpen(
			$bindings,
			$restores ?? $this->silentRestores(),
			new SettleLock(new InMemoryLockingProvider(), $logger),
			new FixedClock(),
			$logger,
			$this->boundPads($bindings, $logger),
		);
	}

	/** A row that waits, last touched $touchedAgo seconds ago - long enough for an open to decide it. */
	private static function waitingRow(int $fileId, string $padId, string $accessMode, int $touchedAgo = 3600): Binding {
		return new Binding(fileId: $fileId, padId: $padId, accessMode: $accessMode, state: BindingService::STATE_RESTORE_PENDING, updatedAt: FixedClock::NOW - $touchedAgo);
	}

	private function silentRestores(): RestoreService {
		$restores = $this->createMock(RestoreService::class);
		$restores->method('settleOpenedFile')->willReturn(SettleOutcome::Unanswered);
		return $restores;
	}
}
