<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Service\AllowlistNormalizer;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AllowlistNormalizerTest extends TestCase {
	public function testEmptyAllowlistNormalizesToEmptyString(): void {
		$this->assertSame('', $this->buildNormalizer()->normalize(" \n, ; "));
	}

	public function testNormalizesHostsAndHttpsOriginsWithPorts(): void {
		$this->assertSame(
			"pad.example.test\nhttps://etherpad.example.org:8443",
			$this->buildNormalizer()->normalize(" pad.example.test, https://etherpad.example.org:8443 ")
		);
	}

	public function testNormalizesMixedSeparators(): void {
		$this->assertSame(
			"one.example.test\ntwo.example.test\nhttps://three.example.test:8443",
			$this->buildNormalizer()->normalize("one.example.test,,two.example.test; https://three.example.test:8443\n")
		);
	}

	/** An entry after an empty one, or after a URL, is kept: neither ends the list. */
	public function testKeepsEveryEntryAfterAnEmptyOneOrAUrl(): void {
		$this->assertSame(
			"pad.example.test\nhttps://one.example.test\ntwo.example.test",
			$this->buildNormalizer()->normalize(',pad.example.test https://one.example.test two.example.test')
		);
	}

	public function testReadsAnHttpsUrlWrittenInCapitals(): void {
		$this->assertSame('https://pad.example.test', $this->buildNormalizer()->normalize('HTTPS://Pad.Example.Test'));
	}

	public function testKeepsTheHighestPort(): void {
		$this->assertSame('https://pad.example.test:65535', $this->buildNormalizer()->normalize('https://pad.example.test:65535'));
	}

	/** A trailing dot names the same host; the list keeps it once, without it. */
	public function testDropsATrailingDotFromAHost(): void {
		$this->assertSame('pad.example.test', $this->buildNormalizer()->normalize('pad.example.test.'));
	}

	public function testDeduplicatesEntries(): void {
		$this->assertSame('pad.example.test', $this->buildNormalizer()->normalize("pad.example.test\nPAD.EXAMPLE.TEST"));
	}

	public function testDropsDefaultHttpsPort(): void {
		$this->assertSame('https://pad.example.test', $this->buildNormalizer()->normalize('https://pad.example.test:443'));
	}

	public function testAllowsHostOnlyIpAddressEntries(): void {
		$this->assertSame('127.0.0.1', $this->buildNormalizer()->normalize('127.0.0.1'));
	}

	public function testRejectsHttpOrigins(): void {
		$this->expectException(AdminValidationException::class);
		// With the entry in it: the admin has to see which one.
		$this->expectExceptionMessage('[de] External allowlist URL must use https: http://pad.example.test');

		$this->buildNormalizer()->normalize('http://pad.example.test');
	}

	public function testRejectsMalformedUrlWithoutWarnings(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('[de] External allowlist URL must use https: https://host:bad');

		$this->buildNormalizer()->normalize('https://host:bad');
	}

	public function testRejectsUrlPath(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('External allowlist URL must use https');

		$this->buildNormalizer()->normalize('https://pad.example.test/foo');
	}

	public function testRejectsUserInfoInUrl(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('External allowlist URL must use https');

		$this->buildNormalizer()->normalize('https://user:pass@pad.example.test');
	}

	public function testRejectsQueryOrFragmentInUrl(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('External allowlist URL must use https');

		$this->buildNormalizer()->normalize('https://pad.example.test?x=1#frag');
	}

	/** Each part a URL entry may not have, refused alone too. */
	#[DataProvider('urlsWithAPartTheyMayNotHave')]
	public function testRejectsAUrlWithAnyPartBeyondSchemeHostAndPort(string $entry): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('[de] External allowlist URL must use https: ' . $entry);

		$this->buildNormalizer()->normalize($entry);
	}

	/** @return array<string, array{string}> */
	public static function urlsWithAPartTheyMayNotHave(): array {
		return [
			'port 0' => ['https://pad.example.test:0'],
			'a user' => ['https://user@pad.example.test'],
			'a query' => ['https://pad.example.test?x=1'],
			'a fragment' => ['https://pad.example.test#top'],
		];
	}

	#[DataProvider('invalidHosts')]
	public function testRejectsInvalidHosts(string $entry): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('[de] External allowlist contains invalid host: ' . $entry);

		$this->buildNormalizer()->normalize($entry);
	}

	/**
	 * A URL in the middle of an entry does not make it one: the entry is a
	 * host, and an invalid one.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalidHosts(): array {
		return [
			'two dots in a row' => ['bad..host'],
			'a hyphen first' => ['-pad.example.test'],
			'a hyphen last' => ['pad.example.test-'],
			'another character first' => ['_pad.example.test'],
			'another character last' => ['pad.example.test_'],
			'a URL inside' => ['pad.example.test/https://other.example.test'],
		];
	}

	private function buildNormalizer(): AllowlistNormalizer {
		return new AllowlistNormalizer($this->buildL10n());
	}

	/**
	 * As Nextcloud's does it: parameters go through vsprintf(), so a
	 * `{name}` in the sentence stays as it is unless the caller fills it.
	 * A double that filled it would pass a message that shows `{host}`.
	 * It knows only the sentences as written, so one filled before it is
	 * translated comes back untranslated.
	 */
	private function buildL10n(): IL10N {
		$sentences = ['External allowlist URL must use https: {host}', 'External allowlist contains invalid host: {host}'];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => (in_array($text, $sentences, true) ? '[de] ' : '') . vsprintf($text, array_values($parameters)),
		);
		return $l10n;
	}
}
