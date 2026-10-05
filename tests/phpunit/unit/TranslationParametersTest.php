<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's IL10N::t() fills its parameters with vsprintf(), which knows
 * `%s` and no `{name}`: a sentence handed `['host' => $host]` reaches the
 * reader with `{host}` as it stands. The app fills every parameter itself,
 * after translating, so no t() is handed any, and no n() more than its
 * count. Test doubles that replace `{name}` in t() hide the mistake, so the
 * source is read for it instead.
 *
 * By PHP's own tokens and not by a pattern over the text: the parameters
 * may be a variable, an `array()` or a list with calls in it, and a
 * pattern that misses one says nothing.
 */
class TranslationParametersTest extends TestCase {
	public function testNoTranslationIsHandedParameters(): void {
		// The rule finds what it is for, and not the right way of doing it.
		foreach ([
			"\$this->l10n->t('External allowlist URL must use https: {host}', ['host' => \$entry]);",
			"\$this->l10n->t(\$message, \$parameters);",
			"\$this->l10n->t(\$message, array(\$origin));",
			"\$l->t(sprintf('%s', strtoupper(trim(\$a))), [\$b]);",
			"\$this->l10n?->t('Deleted %s pads', [\$count]);",
			"\$this->l10n->n('%n pad', '%n pads', \$count, [\$folder]);",
		] as $wrong) {
			$this->assertSame([1], self::linesHandingParameters('<?php ' . $wrong), $wrong);
		}
		foreach ([
			"str_replace('{host}', \$entry, \$this->l10n->t('External allowlist URL must use https: {host}'));",
			"\$this->l10n->t(\$sentences[\$key]('a', 'b'));",
			"\$this->l10n->t('A list, a call: ' . implode(', ', [\$a, \$b]),);",
			"\$this->l10n->n('%n pad', '%n pads', \$count);",
			"\$this->fill(\$this->l10n->t('{url} answered with HTTP {status}.'), ['url' => \$url, 'status' => \$status]);",
		] as $right) {
			$this->assertSame([], self::linesHandingParameters('<?php ' . $right), $right);
		}

		$found = [];
		$scanned = 0;
		$calls = 0;
		foreach (['lib', 'templates'] as $directory) {
			/** @var iterable<\SplFileInfo> $files */
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/' . $directory));
			foreach ($files as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}
				$scanned++;
				foreach (self::linesHandingParameters((string)file_get_contents($file->getPathname()), $calls) as $line) {
					$found[] = $file->getFilename() . ':' . $line;
				}
			}
		}

		$this->assertGreaterThan(100, $scanned, 'the scan has to see the app');
		// Not passed in silence: a reading that sees no call finds none.
		$this->assertGreaterThan(300, $calls, 'the scan has to see the translations');
		$this->assertSame([], $found);
	}

	/**
	 * The lines of the calls in $source that hand a translation its
	 * parameters: t() with more than its sentence, n() with more than its
	 * two sentences and the count. $calls counts the calls read.
	 *
	 * @return list<int>
	 */
	private static function linesHandingParameters(string $source, int &$calls = 0): array {
		$tokens = array_values(array_filter(
			\PhpToken::tokenize($source),
			static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
		));
		$lines = [];
		foreach ($tokens as $i => $token) {
			if (!$token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) || !isset($tokens[$i + 2]) || $tokens[$i + 2]->text !== '(') {
				continue;
			}
			$allowed = ['t' => 1, 'n' => 3][strtolower($tokens[$i + 1]->text)] ?? null;
			if ($allowed === null) {
				continue;
			}
			$calls++;
			// Commas between the call's own brackets, not those of what is
			// inside them; a comma before the closing bracket ends no
			// argument.
			$arguments = 1;
			$depth = 0;
			for ($j = $i + 2; isset($tokens[$j]); $j++) {
				$text = $tokens[$j]->text;
				if (in_array($text, ['(', '[', '{'], true) || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
					$depth++;
				} elseif (in_array($text, [')', ']', '}'], true)) {
					$depth--;
					if ($depth === 0) {
						if ($tokens[$j - 1]->text === ',') {
							$arguments--;
						}
						break;
					}
				} elseif ($depth === 1 && $text === ',') {
					$arguments++;
				}
			}
			if ($arguments > $allowed) {
				$lines[] = $token->line;
			}
		}
		return $lines;
	}
}
