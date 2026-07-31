<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RegisterTemplateCreatorListener;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCP\EventDispatcher\Event;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Files\Template\ITemplateManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class RegisterTemplateCreatorListenerTest extends TestCase {
	public function testRegistersTheTemplateCreatorWhenProtectedPadsAreEnabled(): void {
		$templateManager = $this->createMock(ITemplateManager::class);
		$templateManager->expects($this->once())->method('registerTemplateFileCreator');

		$this->buildListener(true)->handle($this->buildEvent($templateManager));
	}

	public function testSkipsRegistrationWhenProtectedPadsAreDisabled(): void {
		// Nextcloud's own "+ New pad" entry always produces a protected pad,
		// so offering it while that type is off would hand users a dead end.
		$templateManager = $this->createMock(ITemplateManager::class);
		$templateManager->expects($this->never())->method('registerTemplateFileCreator');

		$this->buildListener(false)->handle($this->buildEvent($templateManager));
	}

	public function testIgnoresUnrelatedEvents(): void {
		$this->buildListener(true)->handle(new Event());

		self::assertTrue(true); // not throwing is the assertion
	}

	private function buildEvent(ITemplateManager $templateManager): RegisterTemplateCreatorEvent {
		return new RegisterTemplateCreatorEvent($templateManager);
	}

	private function buildListener(bool $protectedEnabled): RegisterTemplateCreatorListener {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (
				$key === PadTypePolicy::SETTING_PROTECTED
					? ($protectedEnabled ? 'yes' : 'no')
					: $default
			)
		);

		return new RegisterTemplateCreatorListener($l10n, new PadTypePolicy($config));
	}
}
