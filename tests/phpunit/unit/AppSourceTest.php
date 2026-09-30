<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Tests\Support\AppSource;
use PHPUnit\Framework\TestCase;

/**
 * The tests that read the app's source for what it declares are as good
 * as that reading. Held here to the declarations it has to find, and to
 * the text that only looks like one.
 */
class AppSourceTest extends TestCase {
	private const SOURCE = <<<'PHP'
		<?php
		namespace App\Example;

		/** A public field of an argument ends up in a trace: $inAComment */
		final class Carrier {
			use SomeTrait { a as b; }

			public const NAMES = ['public string $inAConstant'];

			public string $declared;
			readonly string $withoutTheWord;
			static $static = ['a' => [1, 2], 'b' => "{$nested}"];
			var $old;
			public ?string $first = null, $second;
			public $interpolated = "${inAString} and {$another}", $afterIt;
			private string $private;
			protected $protected;

			#[Attribute('public string $inAnAttribute')]
			public function __construct(
				public readonly string $promoted,
				readonly array $promotedWithoutTheWord,
				private readonly string $promotedPrivate,
				protected string $promotedProtected,
				string $plain = 'public string $inADefault',
				string $interpolatedDefault = "${inAString}",
				?int $nullable = null,
				string|int ...$rest,
			) {
				$local = 'public string $inAString';
				$anonymous = new class {
					public string $inAnAnonymousClass;
				};
			}

			abstract protected function open(ParsedPadFile $pad, bool &$found, mixed $fileParam, $untyped, array $sessions = []): string;

			public static function &byReference(\Closure $claim): array {
				return [fn (string $inAClosure) => "public {$claim}"];
			}
		}
		PHP;

	public function testReadsEveryFieldAnythingOutsideTheClassCanRead(): void {
		$fields = array_map(static fn (array $field): string => $field[1], AppSource::of(self::SOURCE, 'Carrier')['fields']);

		$this->assertSame(
			['declared', 'withoutTheWord', 'static', 'old', 'first', 'second', 'interpolated', 'afterIt', 'promoted', 'promotedWithoutTheWord'],
			$fields,
		);
	}

	public function testReadsEveryMethodWithItsParametersAsWritten(): void {
		$methods = AppSource::of(self::SOURCE, 'Carrier')['methods'];

		$this->assertSame([
			['App\\Example\\Carrier', '__construct', [
				['string', 'promoted'],
				['array', 'promotedWithoutTheWord'],
				['string', 'promotedPrivate'],
				['string', 'promotedProtected'],
				['string', 'plain'],
				['string', 'interpolatedDefault'],
				['?int', 'nullable'],
				['string|int', 'rest'],
			]],
			['App\\Example\\Carrier', 'open', [
				['ParsedPadFile', 'pad'],
				['bool', 'found'],
				['mixed', 'fileParam'],
				['', 'untyped'],
				['array', 'sessions'],
			]],
			['App\\Example\\Carrier', 'byReference', [['\\Closure', 'claim']]],
		], $methods);
	}

	/** And it sees the app: a reading that finds nothing would pass every test that asks it. */
	public function testReadsTheApp(): void {
		$this->assertGreaterThan(300, count(AppSource::methods()));
		$this->assertContains(['OCA\\EtherpadNextcloud\\Service\\Binding', 'padId'], AppSource::publicFields());
		$this->assertNotContains(['OCA\\EtherpadNextcloud\\Service\\ParsedPadFile', 'body'], AppSource::publicFields());
	}
}
