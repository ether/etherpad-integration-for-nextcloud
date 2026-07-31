<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class PadTypePolicyTest extends TestCase {
	public function testBothTypesAreEnabledWhenNothingIsConfigured(): void {
		// An installation that never opens the settings must behave exactly as
		// it did before the settings existed.
		$policy = $this->buildPolicy([]);

		self::assertTrue($policy->isEnabled(BindingService::ACCESS_PROTECTED));
		self::assertTrue($policy->isEnabled(BindingService::ACCESS_PUBLIC));
	}

	public function testReflectsTheConfiguredFlags(): void {
		$policy = $this->buildPolicy([
			PadTypePolicy::SETTING_PROTECTED => 'no',
			PadTypePolicy::SETTING_PUBLIC => 'yes',
		]);

		self::assertFalse($policy->isEnabled(BindingService::ACCESS_PROTECTED));
		self::assertTrue($policy->isEnabled(BindingService::ACCESS_PUBLIC));
	}

	public function testUnknownAccessModeIsNeverEnabled(): void {
		$policy = $this->buildPolicy([]);

		self::assertFalse($policy->isEnabled('something-else'));
	}

	public function testRequireEnabledPassesForAnEnabledType(): void {
		$policy = $this->buildPolicy([]);

		$policy->requireEnabled(BindingService::ACCESS_PUBLIC);

		self::assertTrue(true); // reaching this point is the assertion
	}

	public function testRequireEnabledRejectsADisabledType(): void {
		$policy = $this->buildPolicy([PadTypePolicy::SETTING_PUBLIC => 'no']);

		$this->expectException(PadTypeDisabledException::class);
		$this->expectExceptionMessage('Public pads are disabled');

		$policy->requireEnabled(BindingService::ACCESS_PUBLIC);
	}

	public function testTemplateKeepsItsOwnModeWhenThatTypeIsEnabled(): void {
		$policy = $this->buildPolicy([]);

		self::assertSame(
			BindingService::ACCESS_PUBLIC,
			$policy->resolveForTemplate(BindingService::ACCESS_PUBLIC)
		);
	}

	public function testTemplateFallsBackToTheEnabledTypeInsteadOfFailing(): void {
		// The template's content is what the user is after; the disabled mode
		// must not cost them the pad.
		$policy = $this->buildPolicy([PadTypePolicy::SETTING_PUBLIC => 'no']);

		self::assertSame(
			BindingService::ACCESS_PROTECTED,
			$policy->resolveForTemplate(BindingService::ACCESS_PUBLIC)
		);
	}

	public function testTemplateFallsBackToPublicWhenProtectedIsDisabled(): void {
		$policy = $this->buildPolicy([PadTypePolicy::SETTING_PROTECTED => 'no']);

		self::assertSame(
			BindingService::ACCESS_PUBLIC,
			$policy->resolveForTemplate(BindingService::ACCESS_PROTECTED)
		);
	}

	public function testTemplateFailsWhenNoPadTypeIsEnabledAtAll(): void {
		$policy = $this->buildPolicy([
			PadTypePolicy::SETTING_PROTECTED => 'no',
			PadTypePolicy::SETTING_PUBLIC => 'no',
		]);

		$this->expectException(PadTypeDisabledException::class);

		$policy->resolveForTemplate(BindingService::ACCESS_PROTECTED);
	}

	/** @param array<string,string> $values */
	private function buildPolicy(array $values): PadTypePolicy {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $values[$key] ?? $default
		);
		return new PadTypePolicy($config);
	}
}
