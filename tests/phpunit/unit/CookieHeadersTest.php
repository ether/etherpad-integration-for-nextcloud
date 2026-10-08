<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Tests\Support\RecordingCookieHeaders;
use PHPUnit\Framework\TestCase;

class CookieHeadersTest extends TestCase {
	/**
	 * Nextcloud's session cookie goes out through `header()` in the same
	 * request; one sent with `replace` would take its place.
	 */
	public function testACookieIsSentBesideTheOnesBeforeIt(): void {
		$cookies = new RecordingCookieHeaders();

		$cookies->add('sessionID=s.abc; Path=/; Secure');

		$this->assertSame([['Set-Cookie: sessionID=s.abc; Path=/; Secure', false]], $cookies->sent);
	}

	/** An open without a session has no cookie, and no empty line goes out for it. */
	public function testNothingIsSentWithoutACookie(): void {
		$cookies = new RecordingCookieHeaders();

		$cookies->add('');

		$this->assertSame([], $cookies->sent);
	}

	/**
	 * No other class under lib sends a Set-Cookie of its own: through a
	 * response's `addHeader()` it would replace Nextcloud's cookies, and
	 * through `header()` too unless it is told not to. Read from the
	 * source's tokens, so a comment that names the header is no use of it.
	 */
	public function testNothingButCookieHeadersSendsASetCookie(): void {
		$lib = dirname(__DIR__, 3) . '/lib';
		$found = [];
		$files = 0;
		/** @var iterable<\SplFileInfo> $all */
		$all = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib));
		foreach ($all as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$files++;
			$path = substr($file->getPathname(), strlen($lib) + 1);
			foreach (\PhpToken::tokenize((string)file_get_contents($file->getPathname())) as $token) {
				if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])
					&& preg_match('/^["\']?set-cookie/i', $token->text) === 1) {
					$found[$path][] = $token->line;
				}
			}
		}

		$this->assertGreaterThan(100, $files, 'the source under lib was read');
		// CookieHeaders itself among them: a scan that finds nothing proves nothing.
		$this->assertSame(['Http/CookieHeaders.php'], array_keys($found), (string)json_encode($found));
	}
}
