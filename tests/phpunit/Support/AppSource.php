<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

/**
 * What the classes under lib declare, read from their source: every
 * method with its parameters, and every field anything outside the class
 * can read.
 *
 * From the source and not by reflection: the unit suite runs against
 * stubs, and a class whose parent is none of them cannot be loaded. By
 * PHP's own tokens and not by a pattern over the text: a field is public
 * without the word - `readonly string $x`, `static $x`, the second name
 * of `public string $a, $b;` - and the word stands in comments and
 * strings that declare nothing.
 *
 * Read once for all the tests that ask.
 */
final class AppSource {
	/** @var ?array{methods: list<array{string, string, list<array{string, string}>}>, fields: list<array{string, string}>} */
	private static ?array $read = null;

	/**
	 * Every method of a class under lib: the class, the method's name, and
	 * its parameters as written - the type, or '', and the name.
	 *
	 * @return list<array{string, string, list<array{string, string}>}>
	 */
	public static function methods(): array {
		return self::read()['methods'];
	}

	/**
	 * Every field that is neither private nor protected, a promoted
	 * constructor parameter too: the class, and the field's name.
	 *
	 * @return list<array{string, string}>
	 */
	public static function publicFields(): array {
		return self::read()['fields'];
	}

	/** @return array{methods: list<array{string, string, list<array{string, string}>}>, fields: list<array{string, string}>} */
	private static function read(): array {
		if (self::$read !== null) {
			return self::$read;
		}
		$methods = [];
		$fields = [];
		$files = 0;
		/** @var iterable<\SplFileInfo> $found */
		$found = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/lib'));
		foreach ($found as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$files++;
			$declared = self::of((string)file_get_contents($file->getPathname()), $file->getBasename('.php'));
			$methods = [...$methods, ...$declared['methods']];
			$fields = [...$fields, ...$declared['fields']];
		}
		// Not skipped in silence: a reading that sees nothing finds nothing.
		if ($files < 100 || count($methods) < 300) {
			throw new \LogicException('The source under lib was not read: ' . $files . ' files, ' . count($methods) . ' methods.');
		}
		return self::$read = ['methods' => $methods, 'fields' => $fields];
	}

	/**
	 * What one file's source declares. The class goes by the file's name,
	 * in the namespace the source states.
	 *
	 * @return array{methods: list<array{string, string, list<array{string, string}>}>, fields: list<array{string, string}>}
	 */
	public static function of(string $source, string $baseName): array {
		$methods = [];
		$fields = [];
		$tokens = array_values(array_filter(
			\PhpToken::tokenize($source),
			static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
		));
		$count = count($tokens);
		$class = $baseName;
		$depth = 0;
		// The depth a class body's own statements are at, or -1 outside one.
		$body = -1;
		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token->is(T_NAMESPACE) && $i + 1 < $count && $tokens[$i + 1]->is([T_NAME_QUALIFIED, T_STRING])) {
				$class = $tokens[$i + 1]->text . '\\' . $baseName;
				continue;
			}
			if (self::opens($token)) {
				$depth++;
				continue;
			}
			if ($token->text === '}') {
				$depth--;
				if ($depth < $body) {
					$body = -1;
				}
				continue;
			}
			if ($body === -1) {
				// `Name::class` and `new class` open no body of their own here.
				if ($token->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM]) && $i > 0 && !$tokens[$i - 1]->is([T_DOUBLE_COLON, T_NEW])) {
					$body = $depth + 1;
				}
				continue;
			}
			if ($depth !== $body) {
				continue;
			}

			// One statement of the class body, from its first modifier.
			$modifiers = [];
			while ($i < $count && $tokens[$i]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR, T_ABSTRACT, T_FINAL])) {
				$modifiers[] = $tokens[$i]->id;
				$i++;
			}
			if ($i >= $count) {
				break;
			}
			if ($tokens[$i]->is(T_ATTRIBUTE)) {
				$i = self::closing($tokens, $i, '[', ']');
				continue;
			}
			if ($tokens[$i]->is(T_FUNCTION)) {
				$i++;
				if ($tokens[$i]->text === '&') {
					$i++;
				}
				$name = $tokens[$i]->text;
				while ($tokens[$i]->text !== '(') {
					$i++;
				}
				$end = self::closing($tokens, $i, '(', ')');
				$parameters = [];
				foreach (self::parameters(array_slice($tokens, $i + 1, $end - $i - 1)) as [$type, $parameter, $promoted, $hidden]) {
					$parameters[] = [$type, $parameter];
					if ($promoted && !$hidden) {
						$fields[] = [$class, $parameter];
					}
				}
				$methods[] = [$class, $name, $parameters];
				// On to the body, or to the end of a declaration without one.
				$i = $end;
				while ($i < $count && !self::opens($tokens[$i]) && $tokens[$i]->text !== ';') {
					$i++;
				}
				if ($i < $count && self::opens($tokens[$i])) {
					$i = self::closing($tokens, $i, '{', '}');
				}
				continue;
			}
			if ($tokens[$i]->is([T_CONST, T_USE, T_CASE])) {
				while ($i < $count && $tokens[$i]->text !== ';' && !self::opens($tokens[$i])) {
					$i++;
				}
				if ($i < $count && self::opens($tokens[$i])) {
					$i = self::closing($tokens, $i, '{', '}');
				}
				continue;
			}
			// A field, or several under one declaration.
			$hidden = in_array(T_PRIVATE, $modifiers, true) || in_array(T_PROTECTED, $modifiers, true);
			$nested = 0;
			$inDefault = false;
			for (; $i < $count; $i++) {
				$text = $tokens[$i]->text;
				if ($nested === 0 && $text === ';') {
					break;
				}
				if (in_array($text, ['(', '[', '{'], true)) {
					$nested++;
				} elseif (in_array($text, [')', ']', '}'], true)) {
					$nested--;
				} elseif ($nested === 0 && $text === '=') {
					$inDefault = true;
				} elseif ($nested === 0 && $text === ',') {
					$inDefault = false;
				} elseif (!$inDefault && $nested === 0 && $tokens[$i]->is(T_VARIABLE) && !$hidden) {
					$fields[] = [$class, substr($text, 1)];
				}
			}
		}
		return ['methods' => $methods, 'fields' => $fields];
	}

	/** Whether the token opens what a `}` closes: a brace, or one inside a string. */
	private static function opens(\PhpToken $token): bool {
		return $token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]);
	}

	/**
	 * The index of the bracket that closes the one at $from.
	 *
	 * @param list<\PhpToken> $tokens
	 */
	private static function closing(array $tokens, int $from, string $open, string $close): int {
		$nested = 0;
		for ($i = $from, $count = count($tokens); $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token->text === $open || ($open === '{' && self::opens($token)) || ($open === '[' && $token->is(T_ATTRIBUTE))) {
				$nested++;
			} elseif ($token->text === $close) {
				$nested--;
				if ($nested === 0) {
					return $i;
				}
			}
		}
		return count($tokens) - 1;
	}

	/**
	 * The parameters of a list, from the tokens between its brackets: type
	 * as written or '', name, whether it is promoted to a field, and
	 * whether that field is private or protected.
	 *
	 * @param list<\PhpToken> $tokens
	 * @return list<array{string, string, bool, bool}>
	 */
	private static function parameters(array $tokens): array {
		$parameters = [];
		$nested = 0;
		$type = '';
		$promoted = false;
		$hidden = false;
		$named = false;
		foreach ($tokens as $token) {
			$text = $token->text;
			if ($token->is(T_ATTRIBUTE) || in_array($text, ['(', '[', '{'], true)) {
				$nested++;
				continue;
			}
			if (in_array($text, [')', ']', '}'], true)) {
				$nested--;
				continue;
			}
			if ($nested > 0) {
				continue;
			}
			if ($text === ',') {
				[$type, $promoted, $hidden, $named] = ['', false, false, false];
				continue;
			}
			if ($named) {
				// The default value, up to the comma.
				continue;
			}
			if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY])) {
				$promoted = true;
				$hidden = $hidden || $token->is([T_PRIVATE, T_PROTECTED]);
				continue;
			}
			if ($token->is(T_VARIABLE)) {
				$parameters[] = [$type, substr($text, 1), $promoted, $hidden];
				$named = true;
				continue;
			}
			if ($token->is(T_ELLIPSIS) || str_starts_with($text, '&')) {
				continue;
			}
			$type .= $text;
		}
		return $parameters;
	}
}
