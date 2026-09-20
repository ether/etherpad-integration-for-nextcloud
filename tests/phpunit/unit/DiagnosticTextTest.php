<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\DiagnosticText;
use PHPUnit\Framework\TestCase;

class DiagnosticTextTest extends TestCase {
	public function testShortenCutsCharactersRatherThanBytes(): void {
		$shortened = DiagnosticText::shorten(str_repeat('€', 100), 20);

		// The point of the character cut: a byte cut lands inside a
		// multi-byte sequence, and json_encode then refuses the whole array.
		$this->assertTrue(mb_check_encoding($shortened, 'UTF-8'));
		$this->assertNotFalse(json_encode(['detail' => $shortened]));
		$this->assertSame(21, mb_strlen($shortened, 'UTF-8'));
	}

	public function testShortenLeavesTextWithinTheCapAlone(): void {
		$this->assertSame('short enough', DiagnosticText::shorten('  short enough  ', 20));
	}

	public function testWithoutSecretReplacesEverySpellingARequestCarries(): void {
		$secret = 'a b/c';

		$text = DiagnosticText::withoutSecret(
			'raw=a b/c enc=' . rawurlencode($secret) . ' plus=' . urlencode($secret),
			$secret,
		);

		$this->assertStringNotContainsString('a b/c', $text);
		$this->assertStringNotContainsString('a%20b%2Fc', $text);
		$this->assertStringNotContainsString('a+b%2Fc', $text);
	}

	public function testWithoutSecretRedactsAShortSecretAsWell(): void {
		$this->assertStringNotContainsString('k7x', DiagnosticText::withoutSecret('apikey=k7x', 'k7x'));
	}

	public function testWithoutSecretIgnoresAnEmptySecret(): void {
		$this->assertSame('unchanged', DiagnosticText::withoutSecret('unchanged', '   '));
	}

	/**
	 * The order the two are used in is what makes redaction work, so it is
	 * pinned here rather than left to each caller to remember.
	 */
	public function testRedactingBeforeShorteningIsWhatRemovesAStraddlingSecret(): void {
		$secret = 'abcdefghijklmnopqrstuvwxyz0123';
		$message = str_repeat('x', 145) . ' apikey=' . $secret . ' tail';

		$right = DiagnosticText::shorten(DiagnosticText::withoutSecret($message, $secret));
		$wrong = DiagnosticText::withoutSecret(DiagnosticText::shorten($message), $secret);

		$this->assertStringNotContainsString(substr($secret, 0, 6), $right);
		$this->assertStringContainsString(substr($secret, 0, 6), $wrong);
	}
}
