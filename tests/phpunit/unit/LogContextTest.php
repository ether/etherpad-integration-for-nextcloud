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
 * One rule, checked over the source rather than per call site.
 *
 * Handing an exception object to the logger hands Nextcloud every stack
 * frame's arguments, and almost everything this app passes around is a
 * credential or a document. SafeError::context() is the shape that
 * replaces it - what failed, what it said, where it came from.
 *
 * The key does not save it. Under 'exception' Nextcloud runs the
 * serializer, which at least honours registerSensitiveMethods(); under
 * any other key lognormalizer takes the throwable instead and writes
 * getTraceAsString(), where the registration has no say and every string
 * argument keeps its first fifteen characters. So the rule reads the
 * value, not the key.
 *
 * This cannot see a secret written into a context key by hand; that
 * stays a matter of reading the line.
 */
class LogContextTest extends TestCase {
	/**
	 * Either quoting, any spacing, and assignment as well as a literal - a
	 * rule that only knows one spelling is a rule the next person writes
	 * around without meaning to.
	 */
	private const CARRIES_AN_EXCEPTION = '/([\'"])exception\\1\\s*(=>|\\]\\s*=)/';

	/**
	 * What binds a throwable to a name. Read per file rather than kept as
	 * a list here: this app catches under thirteen different names, and a
	 * list of them would go stale the first time someone picks a
	 * fourteenth - silently, which is the failure mode worth avoiding.
	 */
	private const BINDS_A_THROWABLE = [
		'/catch\\s*\\([^)]*?\\$([A-Za-z_][A-Za-z0-9_]*)\\s*\\)/',
		'/\\\\?(?:[A-Za-z_][A-Za-z0-9_]*)?(?:Throwable|Exception|Error)\\s+\\$([A-Za-z_][A-Za-z0-9_]*)/',
	];

	/** Every php file the app ships, not just the ones under lib. */
	private const SEARCHED = ['lib', 'appinfo', 'templates'];

	public function testNoLoggerContextCarriesAThrowable(): void {
		$offenders = [];
		$scanned = 0;
		$repository = dirname(__DIR__, 3);
		foreach (self::SEARCHED as $directory) {
			$root = $repository . '/' . $directory;
			// Not skipped: a rule that quietly stops looking is the failure
			// this whole change exists to stop repeating.
			$this->assertDirectoryExists($root);
			/** @var iterable<\SplFileInfo> $files */
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
			foreach ($files as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}
				$scanned++;
				$source = (string)file_get_contents($file->getPathname());
				$carriesOne = $this->carriesAThrowable($source);
				foreach (explode("\n", $source) as $number => $line) {
					if (preg_match(self::CARRIES_AN_EXCEPTION, $line) === 1
						|| preg_match($carriesOne, $line) === 1) {
						$offenders[] = substr($file->getPathname(), strlen($repository) + 1) . ':' . ($number + 1);
					}
				}
			}
		}

		$this->assertGreaterThan(50, $scanned, 'the rule found almost nothing to read');
		$this->assertSame([], $offenders, "Use SafeError::context() instead:\n" . implode("\n", $offenders));
	}

	/**
	 * A pattern for the names this one file binds a throwable to, in value
	 * position - as a literal entry, as an assignment into an existing
	 * context, and through compact(), which are the three ways a value
	 * reaches a context array. Whole names only, and only where the value
	 * ends there: ->getMessage() on the same name is the point of the
	 * rule, not a breach of it. A name reused for something else in the
	 * same file reads as a hit too, which errs the safe way round.
	 */
	private function carriesAThrowable(string $source): string {
		$names = [];
		foreach (self::BINDS_A_THROWABLE as $binding) {
			if (preg_match_all($binding, $source, $matches) > 0) {
				$names = array_merge($names, $matches[1]);
			}
		}
		if ($names === []) {
			// Nothing can hold a throwable here, so nothing can pass one on.
			return '/(*FAIL)/';
		}
		$alternatives = implode('|', array_map('preg_quote', array_unique($names)));
		return '/(?:=>|\\]\\s*=)\\s*\\$(' . $alternatives . ')\\s*(,|\\)|\\]|;|$)'
			. '|compact\\([^)]*[\\\'"](' . $alternatives . ')[\\\'"]/';
	}
}
