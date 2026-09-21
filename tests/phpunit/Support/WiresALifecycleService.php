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
	private function lifecycleServiceOver(BindingService $bindingService, LoggerInterface $logger): LifecycleService {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$padLifecycle = new ManagedPadLifecycle($etherpadClient, $logger);

		return new LifecycleService(
			$bindingService,
			$this->createMock(PadFileService::class),
			$etherpadClient,
			$padLifecycle,
			$this->deleteOnTrashEnabledConfig(),
			$logger,
			$this->createMock(ISecureRandom::class),
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
			new FixedClock(),
			new ProvisionedPadRollback($bindingService, $padLifecycle, $logger),
		);
	}

	private function deleteOnTrashEnabledConfig(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn(string $app, string $key, string $default = ''): string
				=> $key === 'delete_on_trash' ? 'yes' : $default
		);
		$config->method('getSystemValueBool')->willReturn(false);
		return $config;
	}
}
