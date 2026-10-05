<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Service\AllowlistNormalizer;
use OCP\IL10N;
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
		$this->expectExceptionMessage('External allowlist URL must use https');

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

	public function testRejectsInvalidHosts(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('[de] External allowlist contains invalid host: bad..host');

		$this->buildNormalizer()->normalize('bad..host');
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
