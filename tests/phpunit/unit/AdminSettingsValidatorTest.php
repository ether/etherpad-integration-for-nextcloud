<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\AdminSettingsValidator;
use OCA\EtherpadNextcloud\Service\LegacyImportPolicy;
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
			'delete_pad_with_file' => '0',
			'allow_external_pads' => 'yes',
			'external_pad_allowlist' => 'https://external.example.test:8443',
			'trusted_embed_origins' => 'https://portal.example.test',
		], $this->stored());

		$this->assertSame('https://pad.example.test/base', $result->etherpadHost);
		$this->assertSame('https://pad.example.test/base', $result->etherpadApiHost);
		$this->assertSame('.example.test', $result->etherpadCookieDomain);
		$this->assertSame('new-key', $result->apiKeyToStore()?->reveal());
		$this->assertSame('new-key', $result->effectiveApiKey()->reveal());
		$this->assertSame('1.3.0', $result->etherpadApiVersion);
		$this->assertSame(60, $result->syncIntervalSeconds);
		$this->assertFalse($result->deletePadWithFile);
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

		$this->assertNull($result->apiKeyToStore());
		$this->assertSame('stored-key', $result->effectiveApiKey()->reveal());
	}

	public function testValidateForSaveUsesStoredDefaultsForOptionalSettings(): void {
		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
			'etherpad_api_version' => '1.3.0',
		], $this->stored(
			cookieDomain: '.stored.example.test',
			deletePadWithFile: false,
			allowExternalPads: true,
			trustedEmbedOrigins: 'https://portal.example.test',
			externalPadAllowlist: 'pad.example.org',
		));

		$this->assertSame('.stored.example.test', $result->etherpadCookieDomain);
		$this->assertFalse($result->deletePadWithFile);
		$this->assertTrue($result->allowExternalPads);
		$this->assertSame('pad.example.org', $result->externalPadAllowlist);
		$this->assertSame('https://portal.example.test', $result->trustedEmbedOrigins);
	}

	/**
	 * The admin page disables the allowlist while external pads are off and
	 * sends it empty. Stored so, it would trust every public host the day
	 * they are switched back on.
	 */
	public function testTheAllowlistIsTakenOnlyWhileExternalPadsAreOn(): void {
		$validator = $this->buildValidator();
		$payload = [
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
			'etherpad_api_version' => '1.3.0',
			'external_pad_allowlist' => '',
		];
		$stored = $this->stored(externalPadAllowlist: 'pad.example.org');

		$this->assertSame('pad.example.org', $validator->validateForSave($payload + ['allow_external_pads' => 'false'], $stored)->externalPadAllowlist);
		$this->assertSame('', $validator->validateForSave($payload + ['allow_external_pads' => 'true'], $stored)->externalPadAllowlist);
		$this->assertSame(
			'https://other.example.net',
			$validator->validateForSave(['external_pad_allowlist' => 'https://Other.example.net'] + $payload + ['allow_external_pads' => 'true'], $stored)->externalPadAllowlist,
		);
	}

	/**
	 * A list set by hand that the form would refuse is not checked while the
	 * feature is off: its error would land on a field that is hidden then.
	 */
	public function testAStoredAllowlistIsNotCheckedWhileExternalPadsAreOff(): void {
		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
			'etherpad_api_version' => '1.3.0',
			'allow_external_pads' => 'false',
		], $this->stored(externalPadAllowlist: 'https://pad.example.org/p'));

		$this->assertSame('https://pad.example.org/p', $result->externalPadAllowlist);
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
		$logger->expects($this->once())->method('info')->with(
			'Etherpad API version auto-detection failed; using default API version.',
			$this->callback(static function (array $context): bool {
				// Never the exception object: the settings payload sits in a
				// caller's frame, and a serialized trace prints every frame's
				// arguments - including the api key the admin just typed.
				return !isset($context['exception'])
					&& ($context['error_message'] ?? '') === 'down';
			}),
		);

		$result = $this->buildValidator($etherpadClient, $logger)->validateForHealthCheck([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
		], $this->stored());

		$this->assertSame(EtherpadClient::DEFAULT_API_VERSION, $result->etherpadApiVersion);
	}

	/**
	 * Read once per API host. Asking on every save made each one wait for
	 * Etherpad, and one that did not answer wrote the default over the
	 * version read before. The connection test goes by the same rule.
	 */
	public function testTheStoredVersionStandsWhileTheApiHostStays(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('configuredApiHost')->willReturn('https://pad.example.test');
		$etherpadClient->expects($this->never())->method('detectApiVersion');
		$validator = $this->buildValidator($etherpadClient);
		$payload = [
			'etherpad_host' => 'https://pad.example.test/',
			'etherpad_api_key' => 'key',
		];

		$this->assertSame('1.3.0', $validator->validateForSave($payload, $this->stored(apiVersion: '1.3.0'))->etherpadApiVersion);
		// Set by hand with a line break, as from a file.
		$this->assertSame('1.3.0', $validator->validateForHealthCheck($payload, $this->stored(apiVersion: "1.3.0\n"))->etherpadApiVersion);
	}

	/**
	 * A version read from another server says nothing about this one. A
	 * stored value that is no version is asked again, and so is the
	 * default, which a read that failed may have left behind.
	 */
	public function testTheVersionIsAskedForANewApiHostOrWhenNoneIsStored(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('configuredApiHost')->willReturn('https://old-pad.example.test');
		$etherpadClient->expects($this->exactly(4))->method('detectApiVersion')->willReturn('1.3.1');
		$validator = $this->buildValidator($etherpadClient);
		$payload = [
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_key' => 'key',
		];

		$this->assertSame('1.3.1', $validator->validateForSave($payload, $this->stored(apiVersion: '1.3.0'))->etherpadApiVersion);
		$payload['etherpad_api_host'] = 'https://old-pad.example.test';
		$this->assertSame('1.3.1', $validator->validateForSave($payload, $this->stored())->etherpadApiVersion);
		$this->assertSame('1.3.1', $validator->validateForSave($payload, $this->stored(apiVersion: '1.3'))->etherpadApiVersion);
		$this->assertSame('1.3.1', $validator->validateForSave($payload, $this->stored(apiVersion: EtherpadClient::DEFAULT_API_VERSION))->etherpadApiVersion);
	}

	public function testTheLegacyProtectedImportSwitchFollowsThePayload(): void {
		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_version' => '1.3.0',
			'sync_interval_seconds' => '60',
			// What the form actually posts: URLSearchParams stringifies it.
			LegacyImportPolicy::SETTING_PROTECTED_IMPORT => 'false',
		], $this->stored());

		$this->assertFalse($result->allowLegacyProtectedImport);
	}

	/** Otherwise a save that silently switched the import off would pass. */
	public function testTheLegacyProtectedImportSwitchCanBeTurnedBackOn(): void {
		$stored = new StoredAdminSettings('stored-key', '', true, false, '', true, true, false, false);

		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_version' => '1.3.0',
			'sync_interval_seconds' => '60',
			LegacyImportPolicy::SETTING_PROTECTED_IMPORT => 'true',
		], $stored);

		$this->assertTrue($result->allowLegacyProtectedImport);
	}

	/**
	 * The form always sends the field; this is about every other caller.
	 * Both directions, so a hardcoded false cannot pass.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('storedLegacyProtectedImport')]
	public function testAnAbsentLegacyProtectedImportFieldKeepsWhatWasStored(bool $stored): void {
		$result = $this->buildValidator()->validateForSave([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_version' => '1.3.0',
			'sync_interval_seconds' => '60',
		], new StoredAdminSettings('stored-key', '', true, false, '', true, true, false, $stored));

		$this->assertSame($stored, $result->allowLegacyProtectedImport);
	}

	/** @return iterable<string,array{bool}> */
	public static function storedLegacyProtectedImport(): iterable {
		yield 'stored off' => [false];
		yield 'stored on' => [true];
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
		bool $deletePadWithFile = true,
		bool $allowExternalPads = false,
		string $trustedEmbedOrigins = '',
		string $externalPadAllowlist = '',
		string $apiVersion = '',
	): StoredAdminSettings {
		return new StoredAdminSettings(
			$apiKey,
			$cookieDomain,
			$deletePadWithFile,
			$allowExternalPads,
			$trustedEmbedOrigins,
			externalPadAllowlist: $externalPadAllowlist,
			apiVersion: $apiVersion,
		);
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
