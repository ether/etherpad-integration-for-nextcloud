<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Listeners\RegisterTemplateCreatorListener;
use OCA\EtherpadNextcloud\Service\AppAccess;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Files\Template\ITemplateManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class RegisterTemplateCreatorListenerTest extends TestCase {
	/**
	 * This entry is the only way to create a pad from the Files app, so it has
	 * to survive as long as anything at all can be created — the blank option
	 * produces whichever pad type is available, and the external tile follows
	 * its own setting. Dropping it whenever protected was off left a
	 * public-only instance with no way to create anything; ignoring external
	 * pads left an external-only instance with a tile nobody can open.
	 *
	 * @return iterable<string,array{0:bool,1:bool,2:bool,3:bool}>
	 */
	public static function padTypeProvider(): iterable {
		yield 'both enabled' => [true, true, false, true];
		yield 'protected only' => [true, false, false, true];
		yield 'public only' => [false, true, false, true];
		yield 'external pads only' => [false, false, true, true];
		yield 'nothing enabled' => [false, false, false, false];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('padTypeProvider')]
	public function testRegistersWhileAnythingCanBeCreated(bool $protected, bool $public, bool $external, bool $expected): void {
		$templateManager = $this->createMock(ITemplateManager::class);
		$templateManager->expects($expected ? $this->once() : $this->never())
			->method('registerTemplateFileCreator');

		$this->buildListener($protected, $public, $external)->handle($this->buildEvent($templateManager));
	}

	/**
	 * Nextcloud asks for the entry for every user, also where an admin has
	 * limited the app to groups: a user outside them gets none, and nor does
	 * a request without a user.
	 *
	 * @return iterable<string,array{0:?bool,1:bool}>
	 */
	public static function users(): iterable {
		yield 'a user the app is enabled for' => [true, true];
		yield 'a user the app is not enabled for' => [false, false];
		yield 'no user' => [null, false];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('users')]
	public function testRegistersOnlyForAUserTheAppIsEnabledFor(?bool $enabledForUser, bool $expected): void {
		$templateManager = $this->createMock(ITemplateManager::class);
		$templateManager->expects($expected ? $this->once() : $this->never())
			->method('registerTemplateFileCreator');

		$this->buildListener(true, true, false, $enabledForUser)->handle($this->buildEvent($templateManager));
	}

	public function testIgnoresUnrelatedEvents(): void {
		$this->buildListener(true, true)->handle(new Event());

		self::assertTrue(true); // not throwing is the assertion
	}

	private function buildEvent(ITemplateManager $templateManager): RegisterTemplateCreatorEvent {
		return new RegisterTemplateCreatorEvent($templateManager);
	}

	/** @param ?bool $enabledForUser null for a request without a user */
	private function buildListener(bool $protectedEnabled, bool $publicEnabled = true, bool $externalAllowed = false, ?bool $enabledForUser = true): RegisterTemplateCreatorListener {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($protectedEnabled, $publicEnabled, $externalAllowed): string {
				if ($key === PadTypePolicy::SETTING_PROTECTED) {
					return $protectedEnabled ? 'yes' : 'no';
				}
				if ($key === PadTypePolicy::SETTING_PUBLIC) {
					return $publicEnabled ? 'yes' : 'no';
				}
				if ($key === 'allow_external_pads') {
					return $externalAllowed ? 'yes' : 'no';
				}
				return $default;
			}
		);

		$user = $enabledForUser === null ? null : $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $appId, ?IUser $asked = null): bool => $appId === Application::APP_ID && $asked === $user && $enabledForUser === true
		);

		return new RegisterTemplateCreatorListener($l10n, new PadTypePolicy($config), $config, new AppAccess($appManager), $userSession);
	}
}
