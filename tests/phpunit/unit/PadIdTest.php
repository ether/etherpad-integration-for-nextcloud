<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\PadId;
use PHPUnit\Framework\TestCase;

class PadIdTest extends TestCase {
	/** @return iterable<string, array{string, bool}> */
	public static function ids(): iterable {
		yield 'a public pad' => ['team-notes', true];
		yield 'a group pad as createGroupPad makes it' => ['g.AbCdEfGh12345678$notes', true];
		yield 'a name of fifty characters' => [str_repeat('a', 50), true];
		yield 'fifty characters, not bytes' => [str_repeat('ä', 50), true];
		yield 'a name of fifty-one characters' => [str_repeat('a', 51), false];
		yield 'no name at all' => ['', false];
		yield 'a group of fifteen' => ['g.AbCdEfGh1234567$notes', false];
		yield 'a group of seventeen' => ['g.AbCdEfGh123456789$notes', false];
		yield 'a group with a dash' => ['g.AbCdEfGh-2345678$notes', false];
		yield 'a group without its dot' => ['gxAbCdEfGh12345678$notes', false];
		yield 'a group with a capital G' => ['G.AbCdEfGh12345678$notes', false];
		yield 'a group with an underscore' => ['g.AbCdEfGh_2345678$notes', false];
		yield 'a group with a letter past ASCII' => ['g.AbCdEfGhä2345678$notes', false];
		// The name is counted, not the whole id.
		yield 'a group pad with a name of fifty' => ['g.AbCdEfGh12345678$' . str_repeat('a', 50), true];
		yield 'a group pad with a name of fifty-one' => ['g.AbCdEfGh12345678$' . str_repeat('a', 51), false];
		yield 'a group pad without a name' => ['g.AbCdEfGh12345678$', false];
		yield 'a second $' => ['g.AbCdEfGh12345678$a$b', false];
		yield 'a $ in a public name' => ['a$b', false];
		yield 'a colon' => ['a:b', false];
		yield 'a colon in a group pad' => ['g.AbCdEfGh12345678$a:b', false];
		yield 'a dot' => ['.', false];
		yield 'two dots' => ['..', false];
		// Etherpad asks it of the whole id, as only a whole id is a path.
		yield 'two dots as a group pad\'s name' => ['g.AbCdEfGh12345678$..', true];
		yield 'three dots' => ['...', true];
		yield 'a dot inside a name' => ['a.b', true];
		yield 'fifty UTF-16 units of characters past the BMP' => [str_repeat('😀', 25), true];
		yield 'fifty-two UTF-16 units of characters past the BMP' => [str_repeat('😀', 26), false];
		yield 'forty-nine characters and one past the BMP' => [str_repeat('a', 49) . '😀', false];
		yield 'a line break at the very end' => ["abc\n", false];
		// Each character JavaScript's \s takes.
		foreach ([0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20, 0xA0, 0x1680, ...range(0x2000, 0x200A), 0x2028, 0x2029, 0x202F, 0x205F, 0x3000, 0xFEFF] as $codePoint) {
			yield sprintf('U+%04X', $codePoint) => ['a' . mb_chr($codePoint, 'UTF-8') . 'b', false];
		}
		yield 'whitespace in a group pad\'s name' => ['g.AbCdEfGh12345678$a b', false];
		// Ones it does not: PCRE's \s or \v takes the first two.
		yield 'a next line' => ["a\u{85}b", true];
		yield 'a Mongolian vowel separator' => ["a\u{180E}b", true];
		yield 'a zero-width space' => ["a\u{200B}b", true];
		yield 'a word joiner' => ["a\u{2060}b", true];
		yield 'not UTF-8' => ["\xff", false];
	}

	/**
	 * Etherpad's rule from 3.3.3 (isValidPadId() in its PadManager), and no
	 * whitespace, which its API rewrites: the name counted in UTF-16 units,
	 * as JavaScript counts it, and the pattern's end the string's very end.
	 * The dot after `g` is a dot, stricter than Etherpad's pattern.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('ids')]
	public function testAPadIdIsValidAsEtherpadSaysIt(string $padId, bool $valid): void {
		$this->assertSame($valid, PadId::isValid($padId));
	}

	/** What an id already bound names stays the loose rule's to say. */
	public function testAGroupIsNamedEvenWhereTheIdIsNotValid(): void {
		$this->assertSame('g.abc', PadId::groupIdOf('g.abc$notes'));
		$this->assertFalse(PadId::isValid('g.abc$notes'));
	}
}
