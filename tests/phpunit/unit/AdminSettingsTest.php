<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\CookieDomainMessages;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCA\EtherpadNextcloud\Settings\AdminSettings;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class AdminSettingsTest extends TestCase {
	/**
	 * The cookie warning has to be on the page before anyone runs a
	 * connection test — that is the whole point of the check doing no I/O.
	 */
	public function testWarnsAboutAnUnusableCookieDomainOnFirstLoad(): void {
		$params = $this->renderWith('https://pad.unrelated.test', true);

		$this->assertStringContainsString('cloud.example.test', (string)$params['cookie_domain_warning']);
		$this->assertStringContainsString('pad.unrelated.test', (string)$params['cookie_domain_warning']);
	}

	/** An instance offering only public pads has nothing to fix here. */
	public function testStaysSilentWhenProtectedPadsAreOff(): void {
		$params = $this->renderWith('https://pad.unrelated.test', false);

		$this->assertSame('', (string)$params['cookie_domain_warning']);
	}

	public function testStaysSilentWhenTheHostsShareAParentDomain(): void {
		$params = $this->renderWith('https://pad.example.test', true);

		$this->assertSame('', (string)$params['cookie_domain_warning']);
	}

	/** @return array<string,mixed> */
	private function renderWith(string $etherpadHost, bool $protectedPadsEnabled): array {
		$appValues = [
			'etherpad_host' => $etherpadHost,
			'enable_protected_pads' => $protectedPadsEnabled ? 'yes' : 'no',
		];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn(string $app, string $key, string $default = ''): string => $appValues[$key] ?? $default
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.test');
		$urlGenerator->method('linkToRoute')->willReturn('/route');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				foreach ($parameters as $key => $value) {
					$text = str_replace('{' . $key . '}', (string)$value, $text);
				}
				return $text;
			}
		);

		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->method('getTrustedEmbedOriginsRaw')->willReturn('');
		$repository = $this->createMock(AdminSettingsRepository::class);
		$repository->method('hasApiKey')->willReturn(true);

		$settings = new AdminSettings(
			$config,
			$urlGenerator,
			$l10n,
			$appConfigService,
			$repository,
			new CookieDomainPolicy(),
			new CookieDomainMessages($l10n),
			new PadTypePolicy($config),
		);

		return $settings->getForm()->getParams();
	}
}
