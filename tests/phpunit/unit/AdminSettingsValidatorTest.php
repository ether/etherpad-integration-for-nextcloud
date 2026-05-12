<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\AdminSettingsValidator;
use OCA\EtherpadNextcloud\Service\AllowlistNormalizer;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\StoredAdminSettings;
use OCA\EtherpadNextcloud\Service\TrustedEmbedOriginsNormalizer;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminSettingsValidatorTest extends TestCase {
	public function testValidateForSaveNormalizesPayload(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('detectApiVersion');

		$result = $this->buildValidator($etherpadClient)->validateForSave([
			'etherpad_host' => 'https://PAD.example.test/base/',
			'etherpad_api_host' => '',
			'etherpad_cookie_domain' => '.Example.Test',
			'etherpad_api_key' => ' new-key ',
			'etherpad_api_version' => '1.3.0',
			'sync_interval_seconds' => '60',
			'delete_on_trash' => '0',
			'allow_external_pads' => 'yes',
			'external_pad_allowlist' => 'https://external.example.test:8443',
			'trusted_embed_origins' => 'https://portal.example.test',
		], $this->stored());

		$this->assertSame('https://pad.example.test/base', $result->etherpadHost);
		$this->assertSame('https://pad.example.test/base', $result->etherpadApiHost);
		$this->assertSame('.example.test', $result->etherpadCookieDomain);
		$this->assertSame('new-key', $result->etherpadApiKey);
		$this->assertSame('new-key', $result->effectiveApiKey);
		$this->assertSame('1.3.0', $result->etherpadApiVersion);
		$this->assertSame(60, $result->syncIntervalSeconds);
		$this->assertFalse($result->deleteOnTrash);
		$this->assertTrue($result->allowExternalPads);
		$this->assertSame('https://external.example.test:8443', $result->externalPadAllowlist);
		$this->assertSame('https://portal.example.test', $result->trustedEmbedOrigins);
	}

	public function testValidateForSaveUsesStoredApiKeyWhenInputIsBlank(): void {
		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => '',
			'etherpad_api_version' => '1.3.0',
		], $this->stored(apiKey: 'stored-key'));

		$this->assertNull($result->etherpadApiKey);
		$this->assertSame('stored-key', $result->effectiveApiKey);
	}

	public function testValidateForSaveUsesStoredDefaultsForOptionalSettings(): void {
		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
			'etherpad_api_version' => '1.3.0',
		], $this->stored(
			cookieDomain: '.stored.example.test',
			deleteOnTrash: false,
			allowExternalPads: true,
			trustedEmbedOrigins: 'https://portal.example.test',
		));

		$this->assertSame('.stored.example.test', $result->etherpadCookieDomain);
		$this->assertFalse($result->deleteOnTrash);
		$this->assertTrue($result->allowExternalPads);
		$this->assertSame('', $result->externalPadAllowlist);
		$this->assertSame('https://portal.example.test', $result->trustedEmbedOrigins);
	}

	public function testValidateRejectsMissingApiKey(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Etherpad API key is required.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
		], $this->stored(apiKey: ''));
	}

	public function testValidateRejectsNonHttpsPublicHost(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Etherpad Base URL must use https.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'http://pad.example.test',
			'etherpad_api_key' => 'key',
		], $this->stored());
	}

	public function testValidateRejectsInvalidPublicHost(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Invalid Etherpad Base URL.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://',
			'etherpad_api_key' => 'key',
		], $this->stored());
	}

	public function testValidateRejectsQueryInPublicHost(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Etherpad Base URL must not include query or fragment.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test?bad=1',
			'etherpad_api_key' => 'key',
		], $this->stored());
	}

	public function testValidateAllowsHttpApiHost(): void {
		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_host' => 'http://pad-api.internal:9001/',
			'etherpad_api_key' => 'key',
			'etherpad_api_version' => '1.3.0',
		], $this->stored());

		$this->assertSame('http://pad-api.internal:9001', $result->etherpadApiHost);
	}

	public function testValidateNormalizesIpv6PublicHost(): void {
		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://[::1]:9001/base/',
			'etherpad_api_key' => 'key',
			'etherpad_api_version' => '1.3.0',
		], $this->stored());

		$this->assertSame('https://[::1]:9001/base', $result->etherpadHost);
		$this->assertSame('https://[::1]:9001/base', $result->etherpadApiHost);
	}

	public function testValidateNormalizesIpv6ApiHost(): void {
		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_host' => 'http://[::1]:9001/',
			'etherpad_api_key' => 'key',
			'etherpad_api_version' => '1.3.0',
		], $this->stored());

		$this->assertSame('http://[::1]:9001', $result->etherpadApiHost);
	}

	public function testValidateRejectsUnsupportedApiHostScheme(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Etherpad API URL must use http or https.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_host' => 'ftp://pad-api.example.test',
			'etherpad_api_key' => 'key',
		], $this->stored());
	}

	public function testValidateRejectsQueryInApiHost(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Etherpad API URL must not include query or fragment.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_host' => 'https://pad-api.example.test?bad=1',
			'etherpad_api_key' => 'key',
		], $this->stored());
	}

	public function testValidateRejectsCookieDomainUrl(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Cookie domain must be a hostname, not a URL.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_cookie_domain' => 'https://example.test',
			'etherpad_api_key' => 'key',
		], $this->stored());
	}

	public function testValidateRejectsCookieDomainIpAddress(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Cookie domain must be a valid shared hostname.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_cookie_domain' => '127.0.0.1',
			'etherpad_api_key' => 'key',
		], $this->stored());
	}

	public function testValidateRejectsInvalidCookieDomainLabel(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Cookie domain must be a valid shared hostname.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_cookie_domain' => '-bad.example.test',
			'etherpad_api_key' => 'key',
		], $this->stored());
	}

	public function testValidateRejectsTooLowSyncInterval(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Sync interval must be between 5 and 3600 seconds.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
			'sync_interval_seconds' => 2,
		], $this->stored());
	}

	public function testValidateRejectsTooHighSyncInterval(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Sync interval must be between 5 and 3600 seconds.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
			'sync_interval_seconds' => 3601,
		], $this->stored());
	}

	public function testValidateRejectsInvalidApiVersionFormat(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Invalid Etherpad API version format.');

		$this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
			'etherpad_api_version' => '1.3',
		], $this->stored());
	}

	public function testValidateAutoDetectsApiVersion(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('detectApiVersion')
			->with('https://pad.example.test')
			->willReturn('1.3.0');

		$result = $this->buildValidator($etherpadClient)->validateForHealthCheck([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
		], $this->stored());

		$this->assertSame('1.3.0', $result->etherpadApiVersion);
	}

	public function testValidateLogsAndFallsBackWhenApiVersionDetectionFails(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('detectApiVersion')->willThrowException(new EtherpadClientException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Etherpad API version auto-detection failed; using default API version.');

		$result = $this->buildValidator($etherpadClient, $logger)->validateForHealthCheck([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
		], $this->stored());

		$this->assertSame(EtherpadClient::DEFAULT_API_VERSION, $result->etherpadApiVersion);
	}

	private function buildValidator(?EtherpadClient $etherpadClient = null, ?LoggerInterface $logger = null): AdminSettingsValidator {
		$l10n = $this->buildL10n();
		return new AdminSettingsValidator(
			$l10n,
			new AllowlistNormalizer($l10n),
			new TrustedEmbedOriginsNormalizer($l10n),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	private function stored(
		string $apiKey = 'stored-key',
		string $cookieDomain = '',
		bool $deleteOnTrash = true,
		bool $allowExternalPads = false,
		string $trustedEmbedOrigins = '',
	): StoredAdminSettings {
		return new StoredAdminSettings($apiKey, $cookieDomain, $deleteOnTrash, $allowExternalPads, $trustedEmbedOrigins);
	}

	private function buildL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				foreach ($parameters as $key => $value) {
					$text = str_replace('{' . $key . '}', (string)$value, $text);
				}
				return $text;
			}
		);
		return $l10n;
	}
}
