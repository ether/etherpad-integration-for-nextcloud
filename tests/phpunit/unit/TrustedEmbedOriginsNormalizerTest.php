<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Service\TrustedEmbedOriginsNormalizer;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class TrustedEmbedOriginsNormalizerTest extends TestCase {
	private const SENTENCES = [
		'Trusted embed origins must be absolute origins: {origin}',
		'Trusted embed origins must not include a path: {origin}',
		'Trusted embed origins must not include credentials, query, or fragment: {origin}',
		'Trusted embed origins must use https: {origin}',
		'Trusted embed origins must use a valid TCP port: {origin}',
	];

	/**
	 * Each refusal in its own sentence, translated and then filled with the
	 * entry: the admin has to see which one.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
	public function testNormalizeRefusesWithTheEntry(string $entry, string $sentence): void {
		try {
			$this->buildNormalizer()->normalize($entry);
			$this->fail('not refused');
		} catch (AdminValidationException $e) {
			$this->assertSame('[de] ' . str_replace('{origin}', $entry, $sentence), $e->getMessage());
		}
	}

	/** @return array<string, array{string, string}> */
	public static function refusals(): array {
		return [
			'no scheme' => ['portal.example.test', self::SENTENCES[0]],
			'a path' => ['https://portal.example.test/app', self::SENTENCES[1]],
			'credentials' => ['https://user@portal.example.test', self::SENTENCES[2]],
			'http' => ['http://portal.example.test', self::SENTENCES[3]],
			'port zero' => ['https://portal.example.test:0', self::SENTENCES[4]],
		];
	}

	public function testNormalizeAcceptsUpperTcpPortBoundary(): void {
		$this->assertSame(
			'https://portal.example.test:65535',
			$this->buildNormalizer()->normalize('https://portal.example.test:65535')
		);
	}

	public function testNormalizePreservesIpv6Brackets(): void {
		$this->assertSame(
			'https://[::1]:8443',
			$this->buildNormalizer()->normalize('https://[::1]:8443')
		);
	}

	public function testParseSkipsInvalidEntriesWhenNotStrict(): void {
		$this->assertSame(
			['https://portal.example.test'],
			$this->buildNormalizer()->parse('http://bad.example.test, https://portal.example.test')
		);
	}

	private function buildNormalizer(): TrustedEmbedOriginsNormalizer {
		return new TrustedEmbedOriginsNormalizer($this->buildL10n());
	}

	/**
	 * As Nextcloud's does it: parameters go through vsprintf(), so a
	 * `{name}` in the sentence stays as it is unless the caller fills it.
	 * It knows only the sentences as written, so one filled before it is
	 * translated comes back untranslated.
	 */
	private function buildL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => (in_array($text, self::SENTENCES, true) ? '[de] ' : '') . vsprintf($text, array_values($parameters)),
		);

		return $l10n;
	}
}
