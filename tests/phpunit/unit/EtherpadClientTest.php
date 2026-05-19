<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
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

		$client = new EtherpadClient($config);
		$this->assertSame(
			'https://pad.example.test/p/g.group%24pad-name',
			$client->buildPadUrl('g.group$pad-name')
		);
	}

	public function testGetConfiguredOriginNormalizesScheme(): void {
		$client = new EtherpadClient($this->configWithHost('HTTPS://Pad.Example.Test/'));
		$this->assertSame('https://pad.example.test', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginOmitsDefaultPorts(): void {
		$client = new EtherpadClient($this->configWithHost('https://pad.example.test:443'));
		$this->assertSame('https://pad.example.test', $client->getConfiguredOrigin());

		$client = new EtherpadClient($this->configWithHost('http://pad.example.test:80'));
		$this->assertSame('http://pad.example.test', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginKeepsNonDefaultPort(): void {
		$client = new EtherpadClient($this->configWithHost('https://pad.example.test:9001'));
		$this->assertSame('https://pad.example.test:9001', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginAllowsHttp(): void {
		// Unlike `parsePublicPadUrl`, the configured-origin accessor must not
		// enforce https — admins may legitimately run Etherpad on http behind
		// a private network.
		$client = new EtherpadClient($this->configWithHost('http://pad.internal.lan'));
		$this->assertSame('http://pad.internal.lan', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginReturnsEmptyWhenUnconfigured(): void {
		$client = new EtherpadClient($this->configWithHost(''));
		$this->assertSame('', $client->getConfiguredOrigin());
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

	public function testNormalizeAndValidateExternalPublicPadUrlCanonicalizesHttpsUrl(): void {
		$client = new EtherpadClient($this->buildExternalEnabledConfig());

		$result = $client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/My Pad');

		$this->assertSame('https://1.1.1.1', $result['origin']);
		$this->assertSame('My Pad', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/My%20Pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlKeepsLiteralPlusInPadId(): void {
		// `+` is literal in URL path segments. Using urldecode() previously
		// turned `team+pad` into pad-id `team pad`, then re-emitted
		// `/p/team%20pad` which hits a different / non-existent pad.
		$client = new EtherpadClient($this->buildExternalEnabledConfig());
		$result = $client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team+meeting');
		$this->assertSame('team+meeting', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/team%2Bmeeting', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlDecodesPercentEncodedPlus(): void {
		$client = new EtherpadClient($this->buildExternalEnabledConfig());
		$result = $client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team%2Bmeeting');
		$this->assertSame('team+meeting', $result['pad_id']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlAcceptsMatchingAllowlistedOriginWithPort(): void {
		$client = new EtherpadClient($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$result = $client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:8443/p/public-pad');

		$this->assertSame('https://1.1.1.1:8443', $result['origin']);
		$this->assertSame('https://1.1.1.1:8443/p/public-pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsNonMatchingAllowlistedOriginPort(): void {
		$client = new EtherpadClient($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad host is not in the allowlist.');
		$client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:9443/p/public-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsProtectedPadIds(): void {
		$client = new EtherpadClient($this->buildExternalEnabledConfig());

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Only public pad URLs can be linked from external servers.');
		$client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/g.group$protected-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsWhenDisabledByAdmin(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'allow_external_pads') {
					return 'no';
				}
				return $default;
			}
		);

		$client = new EtherpadClient($config);

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad linking is disabled by admin settings.');
		$client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/public-pad');
	}

	private function buildExternalEnabledConfig(string $externalPadAllowlist = ''): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use ($externalPadAllowlist): string {
				if ($appName !== 'etherpad_nextcloud') {
					return $default;
				}
				if ($key === 'allow_external_pads') {
					return 'yes';
				}
				if ($key === 'external_pad_allowlist') {
					return $externalPadAllowlist;
				}
				return $default;
			}
		);

		return $config;
	}
}
