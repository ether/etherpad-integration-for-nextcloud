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
		yield 'not UTF-8' => ["\xff", false];
	}

	/** Etherpad's own rule (isValidPadId() in its PadManager), with the dot after `g` a dot. */
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
