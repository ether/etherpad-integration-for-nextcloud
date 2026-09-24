<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ProvisionedPadRollback;
use OCA\EtherpadNextcloud\Service\RestoreService;
use OCA\EtherpadNextcloud\Service\TestFaults;
use OCA\EtherpadNextcloud\Service\TrashSnapshotWriters;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The lifecycle wired for tests that need the real services rather than a
 * mock, because a mock has no inside: a LifecycleService over the
 * RestoreService it hands restores to, or a RestoreService on its own.
 *
 * One ManagedPadLifecycle, shared by both services and the rollback, the
 * way the container hands it out: a test built on a real object graph that
 * does not reproduce the graph proves less than it looks.
 */
trait WiresTheLifecycle {
	/**
	 * A LifecycleService with a mock for every collaborator the test does
	 * not name, over a real RestoreService built from the same ones. The pad
	 * lifecycle and the rollback are built over the same Etherpad client and
	 * bindings; they log into $padLifecycleLogger, a mock of its own unless
	 * the test wants their lines in $logger too. Deleting on trash is
	 * $deleteOnTrash; no test fault strikes unless the test gives its own.
	 */
	private function lifecycleService(
		?BindingService $bindings = null,
		?EtherpadClient $etherpad = null,
		?PadFileService $padFiles = null,
		bool $deleteOnTrash = true,
		?LoggerInterface $logger = null,
		?LoggerInterface $padLifecycleLogger = null,
		?ISecureRandom $secureRandom = null,
		?UserNodeResolver $nodes = null,
		?PathNormalizer $paths = null,
		?TestFaults $testFaults = null,
	): LifecycleService {
		$bindings ??= $this->createMock(BindingService::class);
		$etherpad ??= $this->createMock(EtherpadClient::class);
		$padFiles ??= $this->createMock(PadFileService::class);
		$logger ??= $this->createMock(LoggerInterface::class);
		$padLifecycleLogger ??= $this->createMock(LoggerInterface::class);
		$padLifecycle = new ManagedPadLifecycle($etherpad, $padLifecycleLogger);
		$appConfig = $this->appConfigDeletingOnTrash($deleteOnTrash);
		$testFaults ??= new TestFaults($this->createMock(IConfig::class), $appConfig);

		return new LifecycleService(
			$bindings,
			$padFiles,
			$padLifecycle,
			$appConfig,
			$logger,
			$nodes ?? $this->createMock(UserNodeResolver::class),
			$paths ?? $this->createMock(PathNormalizer::class),
			new FixedClock(),
			new TrashSnapshotWriters($etherpad, $padFiles, $logger, $testFaults),
			$this->wireRestoreService($bindings, $etherpad, $padFiles, $padLifecycle, $appConfig, $logger, $padLifecycleLogger, $secureRandom, $testFaults),
		);
	}

	/** One whose pad lifecycle and rollback log where the service does. */
	private function lifecycleServiceOver(BindingService $bindingService, LoggerInterface $logger, ?EtherpadClient $etherpadClient = null): LifecycleService {
		return $this->lifecycleService(bindings: $bindingService, etherpad: $etherpadClient, logger: $logger, padLifecycleLogger: $logger);
	}

	/** A RestoreService on its own, wired as lifecycleService() wires the one inside. */
	private function restoreService(
		?BindingService $bindings = null,
		?EtherpadClient $etherpad = null,
		?PadFileService $padFiles = null,
		bool $deleteOnTrash = true,
		?LoggerInterface $logger = null,
		?LoggerInterface $padLifecycleLogger = null,
		?ISecureRandom $secureRandom = null,
		?TestFaults $testFaults = null,
	): RestoreService {
		$etherpad ??= $this->createMock(EtherpadClient::class);
		$padLifecycleLogger ??= $this->createMock(LoggerInterface::class);
		$appConfig = $this->appConfigDeletingOnTrash($deleteOnTrash);
		return $this->wireRestoreService(
			$bindings ?? $this->createMock(BindingService::class),
			$etherpad,
			$padFiles ?? $this->createMock(PadFileService::class),
			new ManagedPadLifecycle($etherpad, $padLifecycleLogger),
			$appConfig,
			$logger ?? $this->createMock(LoggerInterface::class),
			$padLifecycleLogger,
			$secureRandom,
			$testFaults ?? new TestFaults($this->createMock(IConfig::class), $appConfig),
		);
	}

	private function wireRestoreService(
		BindingService $bindings,
		EtherpadClient $etherpad,
		PadFileService $padFiles,
		ManagedPadLifecycle $padLifecycle,
		AppConfigService $appConfig,
		LoggerInterface $logger,
		LoggerInterface $padLifecycleLogger,
		?ISecureRandom $secureRandom,
		TestFaults $testFaults,
	): RestoreService {
		return new RestoreService(
			$bindings,
			$padFiles,
			$etherpad,
			$padLifecycle,
			$appConfig,
			$logger,
			$secureRandom ?? $this->createMock(ISecureRandom::class),
			new ProvisionedPadRollback($bindings, $padLifecycle, $padLifecycleLogger),
			$testFaults,
		);
	}

	private function appConfigDeletingOnTrash(bool $deleteOnTrash): AppConfigService {
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('isDeleteOnTrashEnabled')->willReturn($deleteOnTrash);
		return $appConfig;
	}
}
