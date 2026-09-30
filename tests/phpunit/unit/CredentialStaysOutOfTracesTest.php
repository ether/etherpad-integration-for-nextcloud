<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadOpenTarget;
use OCA\EtherpadNextcloud\Service\PublicPadContext;
use OCA\EtherpadNextcloud\Service\PublicPadOpenTarget;
use OCA\EtherpadNextcloud\Service\StoredAdminSettings;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCA\EtherpadNextcloud\Tests\Support\NamesOfSecrets;
use OCA\EtherpadNextcloud\Tests\Support\ReadsAsATrace;
use OCA\EtherpadNextcloud\Util\ApiKey;
use PHPUnit\Framework\TestCase;

/**
 * The objects a credential travels in - the API key and the settings
 * that hold it, the cookie that carries a pad's sessions, the address that
 * carries a share token - show a serialized trace their class and nothing
 * that opens anything.
 */
class CredentialStaysOutOfTracesTest extends TestCase {
	use ReadsAsATrace;

	private const CREDENTIAL = 'opens-the-pad';

	/** Public fields under a credential's name that open nothing, each with what it holds. */
	private const NOT_A_CREDENTIAL = [
		'OCA\\EtherpadNextcloud\\Service\\HealthCheckResult::$sessionCookieRelease' => 'the Etherpad release that made the session cookie HttpOnly',
		'OCA\\EtherpadNextcloud\\Service\\HealthCheckResult::$cookieDomain' => 'what the health check found of the cookie\'s domain',
		'OCA\\EtherpadNextcloud\\Service\\ValidatedAdminSettings::$etherpadCookieDomain' => 'the domain a cookie is set for',
		'OCA\\EtherpadNextcloud\\Service\\ValidatedAdminSettings::$cookieDomainConfigured' => 'whether that domain was set by hand',
		'OCA\\EtherpadNextcloud\\Service\\StoredAdminSettings::$cookieDomain' => 'the domain a cookie is set for',
		'OCA\\EtherpadNextcloud\\Service\\StoredAdminSettings::$cookieDomainConfigured' => 'whether that domain was set by hand',
	];

	/** @return iterable<string, array{object, \Closure(): list<mixed>}> */
	public static function carriers(): iterable {
		$key = new ApiKey(self::CREDENTIAL);
		yield 'the API key' => [$key, static fn (): array => [$key->reveal()]];
		$validated = new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad-api.example.test',
			'.example.test',
			self::CREDENTIAL,
			self::CREDENTIAL,
			'1.3.0',
			120,
			true,
			false,
			'',
			'',
		);
		yield 'the settings as validated' => [$validated, static fn (): array => [$validated->apiKeyToStore()?->reveal(), $validated->effectiveApiKey()->reveal()]];
		$stored = new StoredAdminSettings(self::CREDENTIAL, '.example.test', true, false, '');
		yield 'the settings as stored' => [$stored, static fn (): array => [$stored->apiKey()->reveal()]];
		$target = new PadOpenTarget(
			file: '/Notes.pad',
			fileId: 7,
			padId: 'g.ABCDEFGHIJKLMNOP$pad-1',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: 'https://pad.example.test/p/pad-1',
			isExternal: false,
			originalPadUrl: '',
			url: 'https://pad.example.test/p/pad-1',
			cookieHeader: self::CREDENTIAL,
			isReadOnlyView: false,
			mayWrite: true,
		);
		yield 'an open, with its session cookie' => [$target, static fn (): array => [$target->cookieHeader()]];
		$public = new PublicPadOpenTarget('https://pad.example.test/p/pad-1', '', self::CREDENTIAL, false);
		yield 'an open through a share, with its session cookie' => [$public, static fn (): array => [$public->cookieHeader()]];
		$context = new PublicPadContext('Notes', 'https://pad.example.test/p/pad-1', false, true, '', self::CREDENTIAL, self::CREDENTIAL);
		yield 'a share\'s page, with the address that carries its token' => [$context, static fn (): array => [$context->contentUrl(), $context->cookieHeader()]];
	}

	/** @param \Closure(): list<mixed> $read */
	#[\PHPUnit\Framework\Attributes\DataProvider('carriers')]
	public function testACarrierShowsATraceNothingOfTheCredential(object $carrier, \Closure $read): void {
		$shown = (string)json_encode(self::asATraceShows($carrier), JSON_THROW_ON_ERROR);

		$this->assertStringContainsString(str_replace('\\', '\\\\', $carrier::class), $shown, 'the class is what a trace has of it');
		$this->assertStringNotContainsString(self::CREDENTIAL, $shown);
		// And it is still there for the code that asks.
		foreach ($read() as $part) {
			$this->assertSame(self::CREDENTIAL, $part);
		}
	}

	/**
	 * No field under one of a credential's names that anything outside its
	 * class can read, anywhere under lib, but for the ones that hold
	 * something else. The names are the ones the parameters are read by
	 * (SensitiveMethodsTest): a field one of them lets pass, the other
	 * would too.
	 */
	public function testNoClassDeclaresACredentialAsAPublicField(): void {
		[$found, $idle] = self::publicFieldsNamed(NamesOfSecrets::CREDENTIAL, self::NOT_A_CREDENTIAL);

		$this->assertSame([], $found);
		$this->assertSame([], $idle, 'an exception nothing needs any more');
	}
}
