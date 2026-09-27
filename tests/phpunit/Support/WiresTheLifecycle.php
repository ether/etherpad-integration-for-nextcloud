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
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
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
	 * A LifecycleService over a real RestoreService, with a mock for every
	 * collaborator the test does not name. The pad lifecycle and the
	 * rollback are built over the same Etherpad client and bindings; they
	 * log into $padLifecycleLogger, a mock of its own unless the test wants
	 * their lines in $logger too. No test fault strikes unless the test
	 * gives its own. A test of the API's ways in alone gives its own
	 * $restores.
	 */
	private function lifecycleService(
		?BindingService $bindings = null,
		?EtherpadClient $etherpad = null,
		?PadFileService $padFiles = null,
		?LoggerInterface $logger = null,
		?LoggerInterface $padLifecycleLogger = null,
		?ISecureRandom $secureRandom = null,
		?UserNodeResolver $nodes = null,
		?TestFaults $testFaults = null,
		?RestoreService $restores = null,
	): LifecycleService {
		return new LifecycleService(
			$nodes ?? $this->createMock(UserNodeResolver::class),
			$restores ?? $this->restoreService($bindings, $etherpad, $padFiles, $logger, $padLifecycleLogger, $secureRandom, $testFaults),
		);
	}

	/** A RestoreService on its own, wired as lifecycleService() wires the one inside. */
	private function restoreService(
		?BindingService $bindings = null,
		?EtherpadClient $etherpad = null,
		?PadFileService $padFiles = null,
		?LoggerInterface $logger = null,
		?LoggerInterface $padLifecycleLogger = null,
		?ISecureRandom $secureRandom = null,
		?TestFaults $testFaults = null,
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
			$testFaults ?? new TestFaults($this->createMock(IConfig::class), $this->createMock(AppConfigService::class)),
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
		TestFaults $testFaults,
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
			$testFaults,
			// Unless a test says otherwise, a file stays where it was read.
			$nodes ?? $this->createMock(UserNodeResolver::class),
		);
	}
}
