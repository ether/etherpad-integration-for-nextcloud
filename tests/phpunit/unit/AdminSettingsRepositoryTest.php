<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AdminSettingsRepositoryTest extends TestCase {
	public function testPersistStoresValidatedSettings(): void {
		$saved = [];
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $value) use (&$saved): void {
				if ($appName === 'etherpad_nextcloud') {
					$saved[$key] = $value;
				}
			}
		);

		$appConfigWrites = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $appName, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$appConfigWrites): bool {
				if ($appName === 'etherpad_nextcloud') {
					$appConfigWrites[$key] = ['value' => $value, 'sensitive' => $sensitive];
				}
				return true;
			}
		);

		(new AdminSettingsRepository($config, $appConfig))->persist(new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad-api.example.test',
			'.example.test',
			'new-key',
			'new-key',
			'1.3.0',
			90,
			false,
			true,
			'https://external.example.test:8443',
			'https://portal.example.test',
		));

		$this->assertSame('https://pad.example.test', $saved['etherpad_host']);
		$this->assertSame('https://pad-api.example.test', $saved['etherpad_api_host']);
		$this->assertSame('.example.test', $saved['etherpad_cookie_domain']);
		$this->assertSame('yes', $saved['etherpad_cookie_domain_configured']);
		// The API key is written through IAppConfig with the sensitive flag,
		// not through IConfig::setAppValue.
		$this->assertArrayNotHasKey('etherpad_api_key', $saved);
		$this->assertSame('new-key', $appConfigWrites['etherpad_api_key']['value']);
		$this->assertTrue($appConfigWrites['etherpad_api_key']['sensitive']);
		$this->assertSame('1.3.0', $saved['etherpad_api_version']);
		$this->assertSame('90', $saved['sync_interval_seconds']);
		$this->assertSame('no', $saved['delete_on_trash']);
		$this->assertSame('yes', $saved['allow_external_pads']);
		$this->assertSame('https://external.example.test:8443', $saved['external_pad_allowlist']);
		$this->assertSame('https://portal.example.test', $saved['trusted_embed_origins']);
	}

	public function testPersistDoesNotOverwriteApiKeyWhenNoNewKeyWasSubmitted(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $appName, string $key): void {
				TestCase::assertFalse($appName === 'etherpad_nextcloud' && $key === 'etherpad_api_key');
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueString');

		(new AdminSettingsRepository($config, $appConfig))->persist(new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad.example.test',
			'',
			null,
			'stored-key',
			'1.3.0',
			120,
			true,
			false,
			'',
			'',
		));
	}

	public function testReadsApiKeyFromSensitiveAppConfig(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default,
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return ($app === 'etherpad_nextcloud' && $key === 'etherpad_api_key') ? '  stored-key  ' : $default;
			}
		);

		$repository = new AdminSettingsRepository($config, $appConfig);
		$this->assertTrue($repository->hasApiKey());
		// getApiKey() is the single read path EtherpadClient uses; it returns
		// the raw decrypted value, getStoredSettings() trims for display.
		$this->assertSame('  stored-key  ', $repository->getApiKey());
		$this->assertSame('stored-key', $repository->getStoredSettings()->apiKey);
	}
}
