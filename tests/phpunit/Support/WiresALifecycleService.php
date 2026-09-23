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
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ProvisionedPadRollback;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * A wired LifecycleService for tests that need the real one rather than a
 * mock, because a mock has no inside.
 *
 * One ManagedPadLifecycle, shared by the service and the rollback, the way
 * the container hands it out: a test built on a real object graph that
 * does not reproduce the graph proves less than it looks.
 */
trait WiresALifecycleService {
	/**
	 * A LifecycleService with a mock for every collaborator the test does
	 * not name. The pad lifecycle and the rollback are built over the same
	 * Etherpad client and bindings; they log into $padLifecycleLogger, a
	 * mock of its own unless the test wants their lines in $logger too. The
	 * config is deleteOnTrashConfig($deleteOnTrash).
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
	): LifecycleService {
		$bindings ??= $this->createMock(BindingService::class);
		$etherpad ??= $this->createMock(EtherpadClient::class);
		$padLifecycleLogger ??= $this->createMock(LoggerInterface::class);
		$padLifecycle = new ManagedPadLifecycle($etherpad, $padLifecycleLogger);

		return new LifecycleService(
			$bindings,
			$padFiles ?? $this->createMock(PadFileService::class),
			$etherpad,
			$padLifecycle,
			$this->deleteOnTrashConfig($deleteOnTrash),
			$logger ?? $this->createMock(LoggerInterface::class),
			$secureRandom ?? $this->createMock(ISecureRandom::class),
			$nodes ?? $this->createMock(UserNodeResolver::class),
			$paths ?? $this->createMock(PathNormalizer::class),
			new FixedClock(),
			new ProvisionedPadRollback($bindings, $padLifecycle, $padLifecycleLogger),
		);
	}

	/** One whose pad lifecycle and rollback log where the service does. */
	private function lifecycleServiceOver(BindingService $bindingService, LoggerInterface $logger, ?EtherpadClient $etherpadClient = null): LifecycleService {
		return $this->lifecycleService(bindings: $bindingService, etherpad: $etherpadClient, logger: $logger, padLifecycleLogger: $logger);
	}

	/** Deleting on trash switched on or off; no test fault, debug off. */
	private function deleteOnTrashConfig(bool $enabled = true): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use ($enabled): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'delete_on_trash') {
					return $enabled ? 'yes' : 'no';
				}
				return $default;
			}
		);
		$config->method('getSystemValueBool')->willReturn(false);
		return $config;
	}
}
