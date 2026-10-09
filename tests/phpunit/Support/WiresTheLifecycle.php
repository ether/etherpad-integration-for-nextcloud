<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ProvisionedPadRollback;
use OCA\EtherpadNextcloud\Service\RestoreService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The restore wired for tests that need the real service rather than a
 * mock, because a mock has no inside.
 *
 * One ManagedPadLifecycle, shared by the service and the rollback, the
 * way the container hands it out: a test built on a real object graph that
 * does not reproduce the graph proves less than it looks.
 */
trait WiresTheLifecycle {
	/**
	 * A real RestoreService, with a mock for every collaborator the test
	 * does not name. The pad lifecycle and the rollback are built over the
	 * same Etherpad client and bindings; they log into $padLifecycleLogger,
	 * a mock of its own unless the test wants their lines in $logger too.
	 */
	private function restoreService(
		?BindingService $bindings = null,
		?EtherpadClient $etherpad = null,
		?PadFileService $padFiles = null,
		?LoggerInterface $logger = null,
		?LoggerInterface $padLifecycleLogger = null,
		?ISecureRandom $secureRandom = null,
		?UserNodeResolver $nodes = null,
	): RestoreService {
		$etherpad ??= $this->createMock(EtherpadClient::class);
		$padLifecycleLogger ??= $this->createMock(LoggerInterface::class);
		return $this->wireRestoreService(
			$bindings ?? $this->createMock(BindingService::class),
			$etherpad,
			$padFiles ?? $this->createMock(PadFileService::class),
			new ManagedPadLifecycle($etherpad, $padLifecycleLogger),
			$logger ?? $this->createMock(LoggerInterface::class),
			$padLifecycleLogger,
			$secureRandom,
			$nodes,
		);
	}

	private function wireRestoreService(
		BindingService $bindings,
		EtherpadClient $etherpad,
		PadFileService $padFiles,
		ManagedPadLifecycle $padLifecycle,
		LoggerInterface $logger,
		LoggerInterface $padLifecycleLogger,
		?ISecureRandom $secureRandom,
		?UserNodeResolver $nodes = null,
	): RestoreService {
		return new RestoreService(
			$bindings,
			$padFiles,
			$etherpad,
			$padLifecycle,
			$logger,
			$secureRandom ?? $this->createMock(ISecureRandom::class),
			new ProvisionedPadRollback($bindings, $padLifecycle, $padLifecycleLogger),
			// Unless a test says otherwise, a file stays where it was read.
			$nodes ?? $this->createMock(UserNodeResolver::class),
			// Waits for a lock without sleeping.
			new PadFileLockRetryService(static function (int $delay): void {
			}),
		);
	}
}
