<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class EtherpadClientTest extends TestCase {
	public function testBuildPadUrlUsesConfiguredHost(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'etherpad_host') {
					return 'https://pad.example.test';
				}
				return $default;
			}
		);

		$client = $this->client($config);
		$this->assertSame(
			'https://pad.example.test/p/g.group%24pad-name',
			$client->buildPadUrl('g.group$pad-name')
		);
	}

	public function testGetConfiguredOriginNormalizesScheme(): void {
		$client = $this->client($this->configWithHost('HTTPS://Pad.Example.Test/'));
		$this->assertSame('https://pad.example.test', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginOmitsDefaultPorts(): void {
		$client = $this->client($this->configWithHost('https://pad.example.test:443'));
		$this->assertSame('https://pad.example.test', $client->getConfiguredOrigin());

		$client = $this->client($this->configWithHost('http://pad.example.test:80'));
		$this->assertSame('http://pad.example.test', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginKeepsNonDefaultPort(): void {
		$client = $this->client($this->configWithHost('https://pad.example.test:9001'));
		$this->assertSame('https://pad.example.test:9001', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginAllowsHttp(): void {
		// Unlike `parsePublicPadUrl`, the configured-origin accessor must not
		// enforce https — admins may legitimately run Etherpad on http behind
		// a private network.
		$client = $this->client($this->configWithHost('http://pad.internal.lan'));
		$this->assertSame('http://pad.internal.lan', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginReturnsEmptyWhenUnconfigured(): void {
		$client = $this->client($this->configWithHost(''));
		$this->assertSame('', $client->getConfiguredOrigin());
	}

	/**
	 * Construct the client with a bare settings-repository mock. None of
	 * these tests exercise the API-key read path (no network calls), so the
	 * default empty getApiKey() return is fine.
	 */
	private function client(IConfig $config): EtherpadClient {
		return new EtherpadClient($config, $this->createMock(AdminSettingsRepository::class));
	}

	private function configWithHost(string $host): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use ($host): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'etherpad_host') {
					return $host;
				}
				return $default;
			}
		);
		return $config;
	}
}
