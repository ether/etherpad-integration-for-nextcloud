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
 * reader with `{host}` as it stands. The app fills a `{name}` itself, after
 * translating. Test doubles that replace `{name}` in t() hide the mistake,
 * so the source is read for it instead.
 */
class TranslationParametersTest extends TestCase {
	/** A call to t() or n() whose parameters are a list with named keys. */
	private const NAMED_PARAMETERS = '/->[tn]\((?:[^()]|\([^()]*\))*?,\s*\[\s*[\'"][A-Za-z_]\w*[\'"]\s*=>/s';

	public function testNoTranslationIsHandedNamedParameters(): void {
		// The rule finds what it is for, and not the right way of doing it.
		foreach ([
			"\$this->l10n->t('External allowlist URL must use https: {host}', ['host' => \$entry])",
			"\$this->l10n->t(\$message, ['origin' => \$origin])",
			"\$l->t(sprintf('%s', \$a), [\"name\" => \$b])",
		] as $wrong) {
			$this->assertSame(1, preg_match(self::NAMED_PARAMETERS, $wrong), $wrong);
		}
		foreach ([
			"str_replace('{host}', \$entry, \$this->l10n->t('External allowlist URL must use https: {host}'))",
			"\$this->l10n->t('Deleted %s pads', [\$count])",
			"\$this->fill(\$this->l10n->t('{url} answered with HTTP {status}.'), ['url' => \$url, 'status' => \$status])",
		] as $right) {
			$this->assertSame(0, preg_match(self::NAMED_PARAMETERS, $right), $right);
		}

		$found = [];
		$scanned = 0;
		foreach (['lib', 'templates'] as $directory) {
			/** @var iterable<\SplFileInfo> $files */
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/' . $directory));
			foreach ($files as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}
				$scanned++;
				if (preg_match(self::NAMED_PARAMETERS, (string)file_get_contents($file->getPathname())) === 1) {
					$found[] = $file->getFilename();
				}
			}
		}

		$this->assertGreaterThan(100, $scanned, 'the scan has to see the app');
		$this->assertSame([], $found);
	}
}
