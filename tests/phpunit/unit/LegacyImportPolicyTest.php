<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\LegacyImportPolicy;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class LegacyImportPolicyTest extends TestCase {
	/** Opt-in: an instance that never opens the settings imports nothing. */
	public function testProtectedImportIsRefusedWhenNothingIsConfigured(): void {
		self::assertFalse($this->buildPolicy([])->allowsProtectedImport());
	}

	public function testProtectedImportFollowsTheSetting(): void {
		self::assertFalse(
			$this->buildPolicy([LegacyImportPolicy::SETTING_PROTECTED_IMPORT => 'no'])
				->allowsProtectedImport()
		);
		self::assertTrue(
			$this->buildPolicy([LegacyImportPolicy::SETTING_PROTECTED_IMPORT => 'yes'])
				->allowsProtectedImport()
		);
	}

	/** Anything that is not the stored "yes" reads as off, not as unset. */
	public function testAnUnrecognisedValueIsNotAllowed(): void {
		self::assertFalse(
			$this->buildPolicy([LegacyImportPolicy::SETTING_PROTECTED_IMPORT => 'true'])
				->allowsProtectedImport()
		);
	}

	/** @param array<string,string> $values */
	private function buildPolicy(array $values): LegacyImportPolicy {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $values[$key] ?? $default
		);
		return new LegacyImportPolicy($config);
	}
}
