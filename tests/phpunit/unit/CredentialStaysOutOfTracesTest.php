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
use OCA\EtherpadNextcloud\Tests\Support\ReadsAsATrace;
use OCA\EtherpadNextcloud\Util\ApiKey;
use PHPUnit\Framework\TestCase;

/**
 * The objects a credential travels in - the API key, the cookie that
 * carries a pad's sessions, the address that carries a share token - show
 * a serialized trace their class and nothing that opens anything.
 *
 * The two settings objects that hold the API key have their case in
 * SensitiveMethodsTest, from before this rule had a test of its own.
 */
class CredentialStaysOutOfTracesTest extends TestCase {
	use ReadsAsATrace;

	private const CREDENTIAL = 'opens-the-pad';

	/** The names a credential goes by as a field. */
	private const NAMES = '\w*(?:[aA]piKey|[sS]ecret|[pP]assword|[tT]oken|[sS]essionIds?|[cC]ookieHeader)\w*|contentUrl';

	/** @return iterable<string, array{object, \Closure(): list<mixed>}> */
	public static function carriers(): iterable {
		$key = new ApiKey(self::CREDENTIAL);
		yield 'the API key' => [$key, static fn (): array => [$key->reveal()]];
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
	 * The names a credential goes by in this app, as public fields
	 * anywhere under lib. A list of names, as for a document: a credential
	 * under a new name passes it.
	 */
	public function testNoClassDeclaresACredentialAsAPublicField(): void {
		foreach (['public readonly string $cookieHeader,', 'public string $contentUrl,', 'public ?string $etherpadApiKey = null;', 'public array $sessionIds = [];', 'public $shareToken;'] as $declaration) {
			$this->assertSame(1, preg_match(self::aPublicField(self::NAMES), $declaration), $declaration);
		}
		foreach (['private readonly string $cookieHeader,', 'public function open(string $token): void {', 'public readonly string $cookieDomain,', 'public readonly string $url, private string $contentUrl', '* a public field of an argument ends up in a trace. */ private string $cookieHeader', "'public-share:' . \$token,"] as $declaration) {
			$this->assertSame(0, preg_match(self::aPublicField(self::NAMES), $declaration), $declaration);
		}

		$this->assertSame([], self::publicFieldsUnder(self::NAMES));
	}
}
