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
		yield 'fifty characters and a line break' => [str_repeat('a', 50) . "\n", false];
		yield 'not UTF-8' => ["\xff", false];
	}

	/**
	 * Etherpad 3.3's rule (isValidPadId() in its PadManager): the name
	 * counted in UTF-16 units, as JavaScript counts it, up to the string's
	 * very end - a final line break gives it no room - and the dot after `g`
	 * a dot.
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
